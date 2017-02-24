<?php

namespace Very;

use Exception;
use ErrorException;
use Very\Contracts\Debug\ExceptionHandler;

class HandleExceptions
{
    /**
     * The application instance.
     *
     * @var \Very\Application
     */
    protected $app;

    /**
     * Bootstrap the given application.
     *
     * @param  \Very\Application $app
     *
     * @return void
     */
    public function bootstrap(Application $app)
    {
        $this->app = $app;
        $app_env   = getenv('APP_ENV');
        $app_env   = $app_env ? $app_env : 'pro';
        define('ENVIRON', $app_env);
        define('DEBUG', (ENVIRON == 'local' || ENVIRON == 'test'));
        if (DEBUG) {
            error_reporting(E_ALL ^ E_NOTICE);
            ini_set('display_errors', 'On');
        } else {
            error_reporting(0);
            ini_set('display_errors', 'Off');
        }
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Convert PHP errors to ErrorException instances.
     *
     * @param  int    $level
     * @param  string $message
     * @param  string $file
     * @param  int    $line
     * @param  array  $context
     *
     * @return void
     *
     * @throws \ErrorException
     */
    public function handleError($level, $message, $file = '', $line = 0, $context = [])
    {
        if (error_reporting() & $level) {
            $e = new ErrorException($message, 0, $level, $file, $line);
            $this->handleException($e);
        }
    }

    /**
     * Handle an uncaught exception from the application.
     *
     * @param  \Throwable $e
     *
     * @return void
     */
    public function handleException($e)
    {
        $this->getExceptionHandler()->report($e);
    }

    /**
     * Handle the PHP shutdown event.
     *
     * @return void
     */
    public function handleShutdown()
    {
        if (!is_null($error = error_get_last()) && $this->isFatal($error['type'])) {
            $this->handleError($error['type'], $error['message'], $error['file'], $error['line']);
        }
        $this->getExceptionHandler()->shutdown();
    }

    /**
     * Determine if the error type is fatal.
     *
     * @param  int $type
     *
     * @return bool
     */
    protected function isFatal($type)
    {
        return in_array($type, [E_COMPILE_ERROR, E_CORE_ERROR, E_ERROR, E_PARSE]);
    }

    /**
     * Get an instance of the exception handler.
     *
     * @return \Very\Contracts\Debug\ExceptionHandler
     */
    protected function getExceptionHandler()
    {
        return $this->app->make(ExceptionHandler::class);
    }
}
