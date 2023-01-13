<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
const SESSION_EXPIRATION_TIME = 1800;
const RESET_TOKEN_TIME = 1200;


class BaseController extends Controller
{
    /**
     * Instance of the main Request object.
     *
     * @var CLIRequest|IncomingRequest
     */
    protected $request;

    /**
     * An array of helpers to be loaded automatically upon
     * class instantiation. These helpers will be available
     * to all other controllers that extend BaseController.
     *
     * @var array
     */
    protected $helpers = [];
    private $curl;

    /**
     * Constructor.
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.

        // E.g.: $this->session = \Config\Services::session();
        date_default_timezone_set(getenv('custom.timezone')==''?'Africa/cairo':getenv('custom.timezone'));
    }
    public function appendHeader()
    {
        if (strtoupper($_SERVER['REQUEST_METHOD']) == "OPTIONS") {
            $this->response->appendHeader('Access-Control-Allow-Origin', '*');
            $this->response->appendHeader('Access-Control-Allow-Methods', '*');
            $this->response->appendHeader('Access-Control-Allow-Credentials', 'true');

            $this->response->appendHeader('Access-Control-Allow-Headers', 'Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

            $this->response->setJSON(array("success", "okay"));
            $this->response->send();
            exit();
        }
        $this->response->appendHeader("Access-Control-Allow-Origin", "*");
        $this->response->appendHeader("Access-Control-Allow-Methods", "*");
        $this->response->appendHeader("Access-Control-Max-Age", 3600);
        $this->response->appendHeader("Access-Control-Allow-Headers", "Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
    }
    
}
