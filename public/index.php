<?php

use CodeIgniter\Boot;
use Config\Paths;

/*
 *---------------------------------------------------------------
 * CHECK PHP VERSION
 *---------------------------------------------------------------
 */

$minPhpVersion = '8.1'; // If you update this, don't forget to update `spark`.
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );

    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}

// The Playwright server is explicitly isolated from the developer database.
// This marker is supplied only by the E2E runner; normal web requests cannot
// select another environment file.
if (getenv('HABITAWEB_E2E_TESTING') === '1') {
    $projectRoot = dirname(__DIR__);
    require_once $projectRoot . '/vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv($projectRoot, '.env.testing'))->load();
    putenv('CI_ENVIRONMENT=development');
    $_ENV['CI_ENVIRONMENT'] = 'development';
    $_SERVER['CI_ENVIRONMENT'] = 'development';

    $e2eBaseUrl = getenv('HABITAWEB_E2E_BASE_URL');
    if (is_string($e2eBaseUrl) && str_starts_with($e2eBaseUrl, 'http://localhost:')) {
        putenv('app.baseURL=' . $e2eBaseUrl);
        $_ENV['app.baseURL'] = $e2eBaseUrl;
        $_SERVER['app.baseURL'] = $e2eBaseUrl;
    }
}

/*
 *---------------------------------------------------------------
 * SET THE CURRENT DIRECTORY
 *---------------------------------------------------------------
 */

// Path to the front controller (this file)
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Ensure the current directory is pointing to the front controller's directory
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

/*
 *---------------------------------------------------------------
 * BOOTSTRAP THE APPLICATION
 *---------------------------------------------------------------
 * This process sets up the path constants, loads and registers
 * our autoloader, along with Composer's, loads our constants
 * and fires up an environment-specific bootstrapping.
 */

// LOAD OUR PATHS CONFIG FILE
// This is the line that might need to be changed, depending on your folder structure.
require FCPATH . '../app/Config/Paths.php';
// ^^^ Change this line if you move your application folder

$paths = new Paths();

// LOAD THE FRAMEWORK BOOTSTRAP FILE
require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
