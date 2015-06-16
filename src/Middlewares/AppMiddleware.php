<?php
namespace Hook\Middlewares;

use Slim;
use Hook\Model\AppKey as AppKey;
use Hook\Model\Module as Module;

use Hook\Package;
// use Hook\Session;
use Hook\Application\Context;
use Hook\Application\Config;

use Hook\Exceptions\NotAllowedException as NotAllowedException;

class AppMiddleware extends Slim\Middleware
{

    public static function decode_query_string()
    {
        $app = Slim\Slim::getInstance();

        // Parse incoming JSON QUERY_STRING
        // OBS: that's pretty much an uggly thing, but we need data types here.
        // Every param is string on query string (srsly?)
        $query_string = $app->environment->offsetGet('QUERY_STRING');
        $query_data = array();

        if (strlen($query_string)>0) {
            $query_data = array();
            parse_str($query_string, $query_params);

            // Remove json data from query params (which just have key as param)
            $last_param = end($query_params);
            if (is_array($last_param) && end($last_param) === "") {
                array_pop($query_params);
            }

            // Decode JSON data from query params
            if (preg_match('/({[^$]+)/', urldecode($query_string), $query)) {
                $query_data = json_decode(urldecode($query[1]), true) ?: array();
            }

            //
            // FIXME:
            // workaround for using opauth/google provider on OAuthController.
            // See OAuthController#fixOauthStrategiesCallback method.
            //

            if (isset($query_params['state'])) {
                parse_str(urldecode($query_params['state']), $state);
                $query_params = array_merge($query_params, $state);
            }

            // Parse remaining regular string variables
            $query_data = array_merge($query_data, $query_params);

            $app->environment->offsetSet('slim.request.query_hash', $query_data);
        }

        return $query_data;
    }

    public function call()
    {
        // The Slim application
        $app = $this->app;

        self::decode_query_string();

        $origin = $app->request->headers->get('ORIGIN', '*');

        // Always keep connection open
        $app->response->headers->set('Connection', 'Keep-Alive');

        // Allow Cross-Origin Resource Sharing
        $app->response->headers->set('Access-Control-Allow-Credentials', 'true');
        $app->response->headers->set('Access-Control-Allow-Methods', 'GET, PUT, POST, DELETE');
        $app->response->headers->set('Access-Control-Allow-Headers', 'x-app-id, x-app-key, x-auth-token, x-http-method-override, content-type, user-agent, accept');

        if ($app->request->isOptions()) {
            // Always allow OPTIONS requests.
            $app->response->headers->set('Access-Control-Allow-Origin', $origin);

        } else {
            // Get application key
            $app_key = Context::validateKey(
                $app->request->headers->get('X-App-Id') ?: $app->request->get('X-App-Id'),
                $app->request->headers->get('X-App-Key') ?: $app->request->get('X-App-Key')
            );

            if ($app_key) {

                // Check the application key allowed origins, and block if necessary.
                if ($app_key->isBrowser()) {
                    $app->response->headers->set('Access-Control-Allow-Origin', $origin);

                    $request_origin = preg_replace("/https?:\/\//", "", $origin);
                    $allowed_origins = Config::get('security.allowed_origins', array($request_origin));
                    $is_origin_allowed = array_filter($allowed_origins, function($allowed_origin) use (&$request_origin) {
                        return fnmatch($allowed_origin, $request_origin);
                    });

                    if (count($is_origin_allowed) == 0) {
                        // throw new NotAllowedException("origin_not_allowed");
                        $app->response->setStatus(403); // forbidden
                        $app->response->headers->set('Content-type', 'application/json');
                        $app->response->setBody(json_encode(array('error' => "origin_not_allowed")));
                        return;
                    }
                }

                // Require custom app packages
                Package\Manager::autoload();

                // // Register session handler
                // Session\Handler::register(Config::get('session.handler', 'database'));

                // Query and compile route module if found
                $route_module_name = strtolower($app->request->getMethod()) . '_' . substr($app->request->getPathInfo(), 1) . '.php';
                $alternate_route_module_name = 'any_' . substr($app->request->getPathInfo(), 1) . '.php';
                $custom_route = Module::where('type', Module::TYPE_ROUTE)->
                    where('name', $route_module_name)->
                    orWhere('name', $alternate_route_module_name)->
                    first();

                if ($custom_route) {
                    // Flag request as "trusted".
                    Context::setTrusted(true);

                    // "Compile" the route to be available for the router
                    $custom_route->compile();
                }

            } else if (!\Hook\Controllers\ApplicationController::isRootOperation()) {
                $app->response->setStatus(403);
                $app->response->setBody(json_encode(array('error' => "Your IP Address is not allowed to perform this operation.")));

                return;
            }

            //
            // Parse incoming JSON data
            if ($app->request->isPost() || $app->request->isPut() || $app->request->isDelete()) {
                $input_data = $app->environment->offsetGet('slim.input');
                $app->environment->offsetSet('slim.request.form_hash', json_decode($input_data, true));
            }

            return $this->next->call();
        }
    }

}
