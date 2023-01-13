<?php

namespace App\Controllers;

use App\Models\CasesModel;
use App\Models\CategoriesModel;
use App\Models\EntityTypeModel;
use App\Models\SettingModel;
use App\Models\UsersModel;
use CodeIgniter\HTTP\ResponseInterface;
use ReflectionException;
use Redis;


class Home extends BaseController
{
    private Redis $redis;
    private $accessData;

    public function __construct()
    {
        helper('cfms');
        $this->redis = new Redis();
        try {
            if ($this->redis->connect("127.0.0.1")) {
                //                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
            } else {
                echo lang('redisConnectionError');
                die();
            }
        } catch (\RedisException $e) {
            echo lang('redisConnectionError') . $e->getMessage();
            die();
        }
        session_write_close();
    }

    public function testRedis()
    {
        echo $this->redis->get('token');
        echo "Redis connected <br />";
        echo $this->redis->ping("hello") . "<br />";
        if ($this->redis->set("token", " hello token 1")) {
            echo "Token saved";
        }
    }

    /**
     * This function permit anyone to access Api, it may require authentication to access api
     */
    public function _secure($token = null)
    {
        //never allow Access-Control-Allow-Origin in production
        if (getenv('CI_ENVIRONMENT') == 'development') {
            $this->appendHeader();
        }
        if (!isset(apache_request_headers()["Authorization"]) && $token == null) {
            $this->response->setStatusCode(401)->setJSON(array("error" => lang('accessDenied'), "message" => lang('notHavePermissionAccessResource')))->send();
            exit();
        }
        $auth = $token == null ? apache_request_headers()["Authorization"] : 'Bearer ' . $token;
        //        $auth = $this->request->getHeader("Authorization");
        if ($auth == null || strlen($auth) < 5) {
            $this->response->setStatusCode(401)->setJSON(array("error" => lang('accessDenied'), "message" => lang('notHavePermissionAccessResource')))->send();
            exit();
        } else {
            try {
                if (preg_match("/Bearer\s((.*))/", $auth, $matches)) {
                    if (($decoded = $this->redis->get($matches[1])) !== false) {
                        $this->accessData = json_decode($decoded);
                        //check if it is current active token
                        $activeToken = $this->redis->get("user_" . $this->accessData->uid . '_active_token');
                        if ($activeToken != $matches[1]) {
                            //destroy this token, it is not the current
                            $this->redis->del($matches[1]);
                            $this->response->setStatusCode(401)->setJSON([
                                "error" => "not-active", "message" => lang('accountSignedOtherComputer')
                            ])->send();
                            exit();
                        }
                        //update session lifetime
                        $this->redis->expire($matches[1], SESSION_EXPIRATION_TIME);
                    } else {
                        $this->response->setStatusCode(401)->setJSON(array("error" => lang('invalidToken'), "message" => lang('invalidAuthentication')))->send();
                        exit();
                    }
                } else {
                    $this->response->setStatusCode(401)->setJSON(array("error" => lang('invalidToken'), "message" => lang('invalidAuthentication')))->send();
                    exit();
                }
            } catch (\Exception $e) {
                $this->response->setStatusCode(401)->setJSON(array("error" => lang('invalidToken'), "message" => $e->getMessage()))->send();
                exit();
            }
        }
    }

    
    public function login(): ResponseInterface
    {
        $this->appendHeader();
        $model = new UsersModel();
        $input = json_decode(file_get_contents('php://input'));
        try {
            $phone = $input->phone;
            $password = $input->password;
            $result = $model->where('phone', $phone)->get()->getRow();
            // var_dump($result); die();
            if ($result != null) {
                if (password_verify($password, $result->password)) {
                        $payload = array(
                            "iat" => time(),
                            "name" => $result->names,
                            "uid" => $result->id,
                            'phone' => $result->phone,
                            "psw" => $result->password,
                            "level" => $result->userType,
                        );
                        $token = sha1('CA' . uniqid(time()));
                        $data = array(
                            'id' => $result->id,
                            'phone' => $result->phone,
                            'name' => $result->names,
                            'position' => $result->userType,
                            'accessToken' => $token,
                        );
                        if ($this->redis->set($token, json_encode($payload), SESSION_EXPIRATION_TIME)) {
                            //set active token to prevent multiple login
                            $this->redis->set("user_" . $result->id . '_active_token', $token);
                            return $this->response->setStatusCode(200)->setJSON($data);
                        } else {
                            return $this->response->setStatusCode(500)->setJSON(array("error" => lang('systemError'), "message" => lang('app.haveIssueEnd')));
                        }
                } else {
                    return $this->response->setStatusCode(403)->setJSON(array("error" => lang('invalidLogin'), "message" => lang('app.usernamePasswordNotCorrect')));
                }
            } else {
                return $this->response->setStatusCode(403)->setJSON(["error" =>
                lang('invalidLogin'), "message" => lang('app.usernamePasswordNotCorrect'), "data" => $result]);
            }
        } catch (ReflectionException $e) {
            return $this->response->setStatusCode(403)->setJSON(array("error" => lang('invalidLogin'), "message" => lang('app.provideRequiredData') . $e->getMessage()));
        }
    }

    public function index(): ResponseInterface
    {
        return $this->response->setJSON(['status' => 'SUCCESS', 'message' => "API is configured successfully\nDate: " . date('Y-m-d H:i:s')]);
    }

    public function getCategories()
    {
        $this->appendHeader();
        $mdl = new CategoriesModel();
        $result = $mdl->select("id as value, title as text")->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }

    public function getAllData()
    {
        $this->_secure();
        $csMdl = new CasesModel();

        $lineBuilder = $csMdl->select("date_format('%mm-%YYYY', created_at) as date, COUNT(id) as num")
                                ->groupBy("date_format('%mm-%YYYY', created_at)")
                                ->orderBy("created_at", "DESC")
                                ->where("status !=", 0);
        
        $BarBuilder = $csMdl->select("date_format('%mm-%YYYY', created_at) as date, COUNT(id) as num")
                                ->groupBy("date_format('%mm-%YYYY', created_at)")
                                ->orderBy("created_at", "DESC");
                                
        $pieBuilder = $csMdl->select("c.title, COUNT(id) as num")
                                ->join("categories c", "c.id = cases.categoryId")
                                ->groupBy("cases.categoryId");
        $result['lineData'] = $lineBuilder->limit(12)->get()->getResultArray();
        $result['barData'] = $BarBuilder->limit(6)->get()->getResultArray();
        $result['pieData'] = $pieBuilder->get()->getResultArray();
        return $this->response->setJSON($result);
    }

    public function getCasesAdmin($withAtt = 0)
    {
        $this->_secure();
        $csMdl = new CasesModel();

        $resultBuilder = $csMdl->select("cases.names,cases.id, cases.phone, DATE(cases.created_at) as date, cases.id_number,cases.categoryId,cases.problem, COALESCE(attorney, ' - ') as attorneyId, COALESCE(u.names, ' - ') as attorney, COALESCE(u.phone, ' - ') as attorneyPhone,if(status = 0, 'Pending', 'Completed') as status, c.title as category")
                    ->join("users u", "u.id = cases.attorney", "LEFT")
                    ->join("categories c", "c.id = cases.categoryId");
        
        if($withAtt != 0) {
            $resultBuilder->where("cases.attorney !=", 0);
        } else {
            $resultBuilder->where("cases.attorney",0);
        }
        if($this->accessData->level == 2 && $withAtt != 0) {
            $resultBuilder->where("cases.attorney", $this->accessData->uid);
        }

        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON($result);
    }

    public function takeCase() 
    {
        $this->_secure();
        $input = json_decode(file_get_contents("php://input"));
        $mdl = new CasesModel();
        try {
            $mdl->save([
                "id" => (int)$input->caseId,
                "attorney" => (int)$this->accessData->uid
            ]);
            return $this->response->setJSON(["message" => "Cases taken successfully"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage()]);
        }
    }

    public function closeCase()
    {
        $this->_secure();
        $input = json_decode(file_get_contents("php://input"));
        $mdl = new CasesModel();
        try {
            $mdl->save([
                "id" => (int)$input->caseId,
                "status" => 1
            ]);
            return $this->response->setJSON(["message" => "Cases Closed successfully"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage()]);
        }
    }
    public function saveCase()
    {
        $this->appendHeader();
        $mdl = new CasesModel();
        $input = json_decode(file_get_contents("php://input"));
        
        if(empty($input)) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "You can't submit empty form"]);
        }
        try {
            $mdl->save([
                "names" => $input->names,
                "phone" => $input->phone,
                "id_number" => $input->idNumber,
                "categoryId" => $input->categoryId,
                "problem" => $input->caseDetails,
                "status" => 0
            ]);
            return $this->response->setJSON(["message" => "Cases received successfully"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Error occured, your case s not receved! Please try again later"]);
        }
    }

    public function getCases() 
    {
        $this->appendHeader();
        $mdl = new CasesModel();
        $input = json_decode(file_get_contents("php://input"));
        
        if(empty($input)) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "You need to enter your d number first"]);
        }

        $result = $mdl->select("cases.names, cases.phone, DATE(cases.created_at) as date, cases.id_number,cases.categoryId,cases.problem, COALESCE(attorney, ' - ') as attorneyId, COALESCE(u.names, ' - ') as attorney, COALESCE(u.phone, ' - ') as attorneyPhone,if(status = 0, 'Pending', 'Completed') as status, c.title as category")
                    ->join("users u", "u.id = cases.attorney", "LEFT")
                    ->join("categories c", "c.id = cases.categoryId")
                    ->where("id_number", $input->ownerId)->get()->getResultArray();
        return $this->response->setJSON($result);
    }
}
