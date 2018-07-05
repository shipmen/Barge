<?php
/**
 * @license   MIT
 * @copyright Copyright (c) 2017
 * @author    : bugbear
 * @date      : 2017/2/20
 * @time      : 上午10:15
 */

namespace Courser;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;
use Hayrick\Http\Request;
use Hayrick\Http\Response;
use Bulrush\Poroutine;
use Generator;
use Psr\Http\Server\MiddlewareInterface;
use Throwable;
use Closure;

class Context
{
    public $request;

    public $response;

    public $middleware = [];

    public $callable = [];

    public $paramNames = [];

    protected $context = [];

    protected $terminator;

    protected $container;

    protected $error;

    public $method = 'get';

    public $collection;

    public static $allowMethods = [
        'get',
        'post',
        'put',
        'delete',
        'options',
        'patch',
    ];


    /**
     * Context constructor.
     *
     * @param $req
     * @param $res
     * @param ContainerInterface $container
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function __construct($req, $res, ContainerInterface $container)
    {

        $this->context['request'] = $req;
        $this->context['response'] = $res;
        $this->container = $container;
        $request = $this->createRequest($req);
        $this->request = $request;
        $this->method = $request->getMethod();
    }

    /**
     * @param ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;
    }


    /**
     * add request handle
     *
     * @param Route $route
     * @param array $params
     * @return void
     */
    public function add(Route $route, array $params = [])
    {
        $callback = $route->callable;
        if (empty($callback)) {
            return;
        }

        $paramNames = $route->getParamNames();
        foreach ($paramNames as $name) {
            if (isset($params[$name])) {
                $this->request->setParam($name, $params[$name]);
            }
        }

        if (is_array($callback)) {
            $this->callable = array_merge($this->callable, $callback);
        } elseif (!in_array($callback, $this->callable)) {
            $this->callable[] = $callback;
        }
    }

    /**
     * mount middleware
     *
     * @param MiddlewareInterface|array $middleware
     */
    public function use($middleware)
    {
        if (is_array($middleware)) {
            $this->middleware = array_merge($this->middleware, $middleware);
        } else {
            $this->middleware[] = $middleware;
        }
    }

    /**
     * @param $name
     * @param $value
     * @return void
     */
    public function setParam($name, $value)
    {
        $this->request->setParam($name, $value);
    }

    /**
     * @param $method
     */
    public function method($method)
    {
        $this->method = $method;
        $this->request = $this->request->withMethod($method);
    }

    /**
     * @return mixed
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function dispatch()
    {
        $handler = array_merge($this->middleware, $this->callable);
        $resolver = new Transducer($handler);
        $response = $resolver->handle($this->request);
        if ($response instanceof ResponseInterface) {
            $this->response = $response;
        } elseif ($response instanceof Response) {
            $this->response = $response;
        } elseif ($response instanceof Generator) {
            $this->response = $response->getReturn();
        } elseif ($response instanceof \ArrayIterator ||
            $response instanceof \ArrayObject ||
            $response instanceof \JsonSerializable ||
            is_array($response)
        ) {
            $reply = new Response();
            $reply = $reply->withHeader('Content-Type', 'application/json');
            $reply = $reply->write($response);
            $this->response = $reply;
        } elseif (is_object($response) && method_exists($response, 'getContent')) {
            $this->response = $response;
        } else {
            $this->response = new Response();
            $this->response->write($response);
        }

        return $this->respond($this->response);
    }

    /**
     * Iterative process the request
     *
     * @param mixed $request
     * @return mixed|Response
     */
    public function transducer(RequestInterface $request)
    {
        $response = null;
        if (count($this->middleware)) {
            $md = array_pop($this->middleware);
            $next = function (RequestInterface $request) {
                return $this->transducer($request);
            };

            if (is_callable($md)) {
                $response = $md($request, $next);
            } elseif (is_array($md)) {
                list($class, $action) = $md;
                $instance = is_object($class) ? $class : new $class();
                $response = $instance->$action($request, $next);
            }

            if ($response instanceof Generator) {
                $response = Poroutine::resolve($response);
            }
        }

        return $response ?? new Response();
    }

    public function error(Throwable $err): Closure
    {
        return function (callable $callable) use ($err) {
            if (is_array($callable) || is_callable($callable)) {
                $response = call_user_func_array($callable, [$this->request, $err]);
            } else {
                $response = $callable($this->request, $err);
            }

            if ($response instanceof Generator) {
                $response = Poroutine::resolve($response);
            }

            return $this->respond($response);
        };
    }


    /**
     * @param $response
     * @return mixed
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function respond($response)
    {
        $response = $response ?? new Response();
        $length = $response->getBody()->getSize();
        $check = $response->getHeader('Content-Length');
        if (!$check && $length) {
            $response = $response->withHeader('Content-Length', $length);
        }

        if ($this->container->has('response.resolver')) {
            $resolver = $this->container->get('response.resolver');
        } else {
            $resolver = Terminator::class;
        }

        $terminator = new $resolver($this->context['response']);

        return $terminator->end($response);
    }


    /**
     * build Request
     *
     * @param null $req
     * @return Request
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \RuntimeException
     */
    public function createRequest($req = null): Request
    {
        if ($this->container->has('request.resolver')) {
            $builder = $this->container->get('request.resolver');
        } else {
            $builder = Relay::createFromGlobal();
        }

        if (is_callable($builder)) {
            $incoming = call_user_func_array($builder, [$req]);
        } elseif (is_object($builder)) {
            $incoming = $builder;
        } else {
            throw new \RuntimeException('Request builder invalid');
        }

        $request = Request::createRequest($incoming);

        return $request;
    }

    /**
     * check context is mounted
     * @return int
     */
    public function isMount()
    {
        return !!count($this->callable) + count($this->middleware);
    }

    /**
     * get context
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
