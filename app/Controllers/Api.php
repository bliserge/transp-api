<?php

namespace App\Controllers;

use App\Models\AccountNumberModel;
use App\Models\BankModel;
use App\Models\EntityFieldDataModel;
use App\Models\EntityModel;
use App\Models\EntityTypeFieldModel;
use App\Models\EntityTypeModel;
use App\Models\MemberModel;
use App\Models\OfferingsModel;
use App\Models\PaymentOfferingsModel;
use App\Models\PaymentsModel;
use App\Models\QuickPaymentTransactionModel;
use App\Models\RoleModel;
use App\Models\SettingModel;
use App\Models\UsersModel;
use App\Models\SmsRecordModel;
use App\Models\quickPayment;
use App\Models\SmsSentModel;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
//use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ReflectionException;
use Redis;


class Api extends BaseController
{
    private $redis;
    private \CodeIgniter\HTTP\CURLRequest $curl;
    /**
     * @var mixed
     */
    private $accessData;

    public function __construct()
    {
        helper('cfms');
        $this->curl = \Config\Services::curlrequest();
        $this->redis = new Redis();
        try {
            if ($this->redis->connect("127.0.0.1")) {
//                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
            } else {
                echo lang('app.redisConnectionError');
                die();
            }
        } catch (\RedisException $e) {
            echo lang('app.redisConnectionError') . $e->getMessage();
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
        if (!isset(apache_request_headers()["Authorization"]) && !isset(apache_request_headers()["authorization"]) && $token == null) {
            $this->response->setStatusCode(401)->setJSON(array("error" => "Access denied", "message" => "You don't have permission to access this resource."))->send();
            exit();
        }
        $t = apache_request_headers()["Authorization"] ?? apache_request_headers()["authorization"];
        $auth = $token == null ? $t : 'Bearer ' . $token;
//        $auth = $this->request->getHeader("Authorization");
        if ($auth == null || strlen($auth) < 5) {
            $this->response->setStatusCode(401)->setJSON(array("error" => "Access denied", "message" => "You don't have permission to access this resource."))->send();
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
                            $this->response->setStatusCode(401)->setJSON(["error" => "not-active"
                                , "message" => "Your account has be signed in on other computer"])->send();
                            exit();
                        }
                        //update session lifetime
                        $this->redis->expire($matches[1], SESSION_EXPIRATION_TIME);

                    } else {
                        $this->response->setStatusCode(401)->setJSON(array("error" => "Invalid token", "message" => "Invalid authentication."))->send();
                        exit();
                    }
                } else {
                    $this->response->setStatusCode(401)->setJSON(array("error" => "Invalid token", "message" => "Invalid authentication."))->send();
                    exit();
                }
            } catch (\Exception $e) {
                $this->response->setStatusCode(401)->setJSON(array("error" => "Invalid token", "message" => $e->getMessage()))->send();
                exit();
            }
        }
    }

    /**
     * This function helps us to log into administration panel it may require
     * Email and Password authentication
     * @return ResponseInterface If authentication success, while status is 1 or 2 it will redirect you to dashboard otherwise Error happen
     */
    public function login(): ResponseInterface
    {
        $model = new UsersModel();
        try {
            $email = $this->request->getPost('email');
            $password = $this->request->getPost('password');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->response->setStatusCode(400)
                    ->setJSON(["status" => 400, "message" => lang('app.invalidEmailFormat')]);
            }
            $result = $model->checkUser($email);
            if ($result != null) {
                $eMdl = new EntityTypeModel();
                $lastLevel = $eMdl->select('level')->orderBy('level', 'desc')->asObject()->first()->level;
                if (empty($result->entityId) || $lastLevel != $result->level) {
                    return $this->response->setStatusCode(400)
                        ->setJSON(["status" => 400, "message" => lang('app.notSupportedAccount')]);
                }
                if (password_verify($password, $result->password)) {
                    if ($result->status == 1) {
                        $today = date('Y-m-d');
//                        $key = sha1(SECRET_KEY . ':' . $this->request->getServer("HTTP_USER_AGENT") . ":" . $this->request->getServer("REMOTE_ADDR"));
                        $payload = array(
                            "iat" => time(),
                            "name" => $result->names,
                            'phone' => $result->phone,
                            "psw" => $result->password,
                            "pst" => $result->groupId,
                            "uid" => $result->id
                        );
                        $token = sha1('CA' . uniqid(time()));
                        $data = [
                            'id' => $result->id,
                            'phone' => $result->phone,
                            'name' => $result->names,
                            'position' => $result->groupId,
                            'accessToken' => $token,
                            'churchDetails'=>$this->checkChurch($result->code)
                        ];
                        if ($this->redis->set($token, json_encode($payload), SESSION_EXPIRATION_TIME)) {
                            //set active token to prevent multiple login
                            $this->redis->set("user_" . $result->id . '_active_token', $token);
                            $switchActiveData = array(
                                'id' => $result->id,
                                'last_login' => time()
                            );
                            $model->save($switchActiveData);
                            return $this->response->setStatusCode(200)->setJSON($data);
                        } else {
                            return $this->response->setStatusCode(500)->setJSON(["status" => 400, "message" => lang('app.haveIssueEnd')]);
                        }
                    } else {
                        return $this->response->setStatusCode(400)->setJSON(["status" => 400, "message" => lang('app.yourAccountLocked')]);
                    }
                } else {
                    return $this->response->setStatusCode(403)->setJSON(["status" => 403, "message" => lang('app.usernamePasswordNotCorrect')]);
                }
            } else {
                return $this->response->setStatusCode(403)->setJSON(["status" => 403, "message" => lang('app.usernamePasswordNotCorrect')]);
            }
        } catch (ReflectionException $e) {
            return $this->response->setStatusCode(403)->setJSON(["status" => 403, "message" => lang('app.provideRequiredData') . $e->getMessage()]);
        }
    }

    public function index()
    {
        return $this->response->setJSON(['status' => lang('app.success'), 'message' => lang('app.apiConfiguredSuccessfully')]);
    }

    public function offerings($lang = 'en'): ResponseInterface
    {
        $mdl = new OfferingsModel();
        $result = $mdl->select('id,title as name')->get()->getResultArray();

        return $this->response->setStatusCode(200)->setJSON($result);
    }

    public function userProfile(): ResponseInterface
    {
        $mdl = new UsersModel();
        $input = json_decode(file_get_contents("php://input"));
        $id = $input->myId;
        $result = $mdl->select('names,phone,email')->where('id', $id)->get()->getResultArray();

        return $this->response->setJSON($result);
    }

    public function changePassword(): ResponseInterface
    {
        $mdl = new UsersModel();
        $input = json_decode(file_get_contents("php://input"));
        $password = $input->currentPassword;
        $newPassword = $input->newPassword;
        $id = $input->myId;
        $result = $mdl->where('id', $id)->first();
        if (!empty($result && password_verify($password, $result['password']))) {
            $dataToUpdate = [
                'password' => password_hash($newPassword, PASSWORD_BCRYPT),
            ];
            $mdl->update($id, $dataToUpdate);
            $data['message'] = lang('app.passwordChanged');
            return $this->response->setJSON($data);
        } else {
            $data['message'] = lang('app.currentPasswordIncorrect');
            return $this->response->setStatusCode(400)->setJSON($data);
        }
    }

    /**
     * @throws ReflectionException
     * @throws \Exception
     */
    public function register(): ResponseInterface
    {
        $mdl = new MemberModel();
        $entityModel = new EntityModel();
        $memberCode = generateMemberCode();
        $phone = validSMSNumber($this->request->getPost('telephone'));
        $churchCode = $this->request->getPost('church_code');
        $result['church_details'] = $entityModel->where('code', $churchCode)->first();
        if ($mdl->where(['phone' => $phone, 'length(entity_id) !=' => '0', 'status' => 1])->get(1)->getRow() != null) {
            return $this->response->setJSON(['status' => 500, 'message' => lang('app.Member already exists in ')
                . $result['church_details']['title']]);
        }
        if ($result['church_details'] != null) {
            $memberId = $mdl->insert([
                'names' => $this->request->getPost('name'),
                'code' => $memberCode,
                'phone' => $phone,
                'entity_id' => $result['church_details']['id'],
            ]);
            $result['data'] = $mdl->where('id', $memberId)->get()->getResultArray();
            $result['status'] = 200;
            $result['message'] = lang('app.successfulCreated');
            $smsMdl = new SmsSentModel();
            $message = generateRegistrationSMS($phone, 'en');
            $status = 0;
//            log_message('critical', 'Registration sms: ' . $message);
            $errorMsg = null;
            $settingsMdl = new SettingModel();
            $settings = $settingsMdl->getSettings(['sms_sender_name']);
            $baseController = new Home();
            if ($baseController->sendSMS($phone, $message, $res, $settings['sms_sender_name'])) {
                $status = 1;
            } else {
                $errorMsg = $res['content'];
            }
            try {
                $smsMdl->save(['phone' => $phone, 'type' => 5, 'typeId' => $memberId, 'smsCount'=> countSMS($message)
                    ,'message'=>$message, 'memberId' => $memberId, 'status' => $status, 'errorMessage' => $errorMsg]);
            } catch (ReflectionException $e) {
                log_message('critical', 'Registration sms not sent: Member id: ' . $memberId.', Message: '.$message);
            }
            return $this->response->setJSON($result);
        } else {
            $result['message'] = lang('app.churchNotFound');
            return $this->response->setStatusCode(401)->setJSON($result);
        }
    }

    public function updateMember(){
        $mdl = new MemberModel();
        $entityModel = new EntityModel();
        $phone = validSMSNumber($this->request->getPost('telephone'));
        $churchCode = $this->request->getPost('church_code');
        $church = $entityModel->where('code', $churchCode)->first();
        
        if ($church == null) {
            return $this->response->setStatusCode(400)->setJSON(["message" => lang('app.churchNotFound')]);
        }


        $mdl->set(['names' => $this->request->getPost('names'),'phone' => $phone, 'entity_id' => $church['id']])
        ->where('code', $this->request->getPost('code'))->update();
        
        return $this->response->setStatusCode(200)->setJSON(["message"=> "member updated successfully"]);
        
    }

    public function checkChurch($churchCode = null)
    {
        $entityModel = new EntityModel();
        $entityTypeModel = new EntityTypeModel();
        $input = json_decode(file_get_contents("php://input"));
        $code = $churchCode == null ? $_POST['code'] : $churchCode;

        $levelResult = $entityModel->select('et.level')->join('entity_type et', 'entity.entityType = et.id')->where('entity.code', $code)->first();
        if (empty($levelResult)) {
            $result['message'] = "church not found";
            return $this->response->setStatusCode(401)->setJSON($result);
        }
        $level = $levelResult['level'];
        $entityNums = $entityTypeModel->select('count(level) as levels')->where('level <=', $level)->get()->getRow();
        $levels = $entityNums->levels;
        $title = $entityTypeModel->select('title')->where('level <=', $level)->orderBy('level', 'DESC')->get()->getResultArray();

        $join = "";
        $query = "";

        $select = "SELECT e" . $title[0]['title'] . ".code as " . $title[0]['title'] . "Code, e" . $title[0]['title'] . ".created_at, ba.accountNumber, b.title as bankName ";
        for ($i = 0; $i <= $levels - 1; $i++) {
            $select .= ", e" . $title[$i]['title'] . ".title as `" . $title[$i]['title'] . "` ";
            if ($i + 1 <= $levels - 1) {
                $join .= " INNER JOIN entity e" . $title[$i + 1]['title'] . " ON e" . $title[$i]['title'] . ".parentId=e" . $title[$i + 1]['title'] . ".id";
            } else {
                continue;
            }
        }
        $select .= "FROM entity e" . $title[0]['title'];
        $join .= " LEFT JOIN bank_account ba on e" . $title[0]['title'] . ".id = ba.entityId LEFT JOIN banks b on ba.bankId = b.id";
        $query .= $select;
        $query .= $join;
        $query .= " where e" . $title[0]['title'] . ".code ='$code'";
        $church['data'] = $entityModel->db->query($query)->getResultArray();

        if ($churchCode != null) {
            $church = $entityModel->db->query($query)->getResultArray();
            return $church;
        } else if (!empty($church)) {
            $church['message'] = $title[0]['title'] . " found";
            return $this->response->setJSON($church);
        } else {
            $result['message'] = $title[0]['title'] . " not found";
            return $this->response->setStatusCode(401)->setJSON($result);
        }
    }
    function quickPayment($phone, $amount, $reference_id, $memberName, $created_at): ResponseInterface
    {
        $lang = 'en';
        try {
            try {
                $payFn = "payFastHub";
                $instructions = '';
                if (getenv('app.country') =='UG'){
                    $payFn = 'stanbicUgPay';
                }else if (getenv('app.country') =='RW'){
                    $payFn = 'mtnRwPay';
                    $instructions = "\n".lang('app.dialPay',['*182*7*1#'], $lang);
                }
                if ($this->$payFn($phone, $amount, $reference_id, $memberName, $created_at)) {
                    return $this->response->setStatusCode(200)
                        ->setJSON(['status' => 200, 'message' => lang('app.paymentRequestSent', [$instructions], $lang),
                            'trxId' => $reference_id]);
                }
            } catch (\Exception $e) {
                log_message('error', lang('app.paymentGatewayFailed') . $e->getMessage());
                return $this->response->setStatusCode(500)
                    ->setJSON(['status' => 500, 'message' => lang('app.paymentFailedTryAgainLater')]);
            }

        } catch (\Exception $e) {
            log_message('error', lang('app.systemErrorAppPayment') . $e->getMessage());
            return $this->response->setStatusCode(500)
                ->setJSON(['status' => 500, 'message' => lang('app.paymentFailedAgainLater')]);
        }
        return $this->response->setStatusCode(500)
            ->setJSON(['status' => 500, 'message' => lang('app.paymentFailedAgainLater')]);
    }

    function payment(): ResponseInterface
    {
        $paymentMdl = new PaymentsModel();
        $pOMdl = new PaymentOfferingsModel();
        $eMdl = new EntityModel();
        $mMdl = new MemberModel();
        $oMdl = new OfferingsModel();
        $lang = 'en';
        $input = json_decode(file_get_contents("php://input"));
//        if (isset($input->debug) && $input->debug==1){
//            echo file_get_contents("php://input");die();
//        }
        $msisdn = validSMSNumber($input->telephone);
        $memberCode = $input->code;
        $churchCode = $input->church_code;
        $fee_type = $input->fee_type;
        if ($memberCode == '299') {
            return $this->response->setStatusCode(404)->setJSON(['status' => 404, 'message' => 'Your cfms account has an issue, please use USSD']);
        }
        $entityData = $eMdl->select('id,title')->where('code', $churchCode)->first();
        if (!is_array($fee_type)) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 404, 'message' => lang('app.invalidDataFeeType')]);
        }
        if ($entityData == null) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 404, 'message' => lang('app.churchNotFound')]);
        }

        $memberData = $mMdl->select('id,names')->where('code', $memberCode)->first();
        if ($memberData == null) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 404, 'message' => lang('app.memberNotFound')]);
        }

        try {
            $sessionId = '100' . $memberData['id'] . time();
            $amount = 0;
            $paymentId = $paymentMdl->insert(['memberId' => $memberData['id'], 'phone' => $msisdn
                , 'churchId' => $entityData['id'], 'sessionId' => $sessionId, 'status' => 0, 'type' => 2]);
            if ($paymentId === false) {
                return $this->response->setStatusCode(500)
                    ->setJSON(['status' => 500, 'message' => lang('app.paymentFailedAgainLater')]);
            }
            foreach ($fee_type as $item) {
                $item = (array)$item;
                $feeData = $oMdl->select('title')->where('id', $item['offering'])->first();
                if ($feeData == null) {
                    return $this->response->setStatusCode(500)->setJSON(['status' => 500, 'message' => lang('app.invalidDataOffering')]);
                }
                $amount += $item['amount'];
                if(isset($item["fee_description"]) && $item["fee_description"] != "")
                    $pOMdl->save(['payment_id' => $paymentId, 'offeringId' => $item['offering'], 'narration' => $item['fee_description'], 'amount' => $item['amount']]);
                else
                    $pOMdl->save(['payment_id' => $paymentId, 'offeringId' => $item['offering'], 'amount' => $item['amount']]);
            }
            $paymentMdl->save(['id' => $paymentId, 'amount' => $amount]);
            $reference_id = getenv('app.shortName') . $paymentId;
            $created_at = date('Y-m-d H:i:s');
            try {
                $paymentMdl->set('trxId', $reference_id)->where('sessionId', $sessionId)->update();
//                        $paymentMdl->db->query('update payments set trxid=? where sessionId=?', [$reference, $sessionId]);

            } catch (\Exception $e) {
                log_message('error', lang('app.updatePaymentFailed') . $e->getMessage()
                    . ' - Payload' . $e->getMessage());
                return $this->response->setStatusCode(500)
                    ->setJSON(['status' => 500, 'message' => lang('app.updatePaymentFailed')]);
            }

            try {
                $payFn = "payFastHub";
                $instructions = '';
                if (getenv('app.country') =='UG'){
                    $payFn = 'stanbicUgPay';
                }else if (getenv('app.country') =='RW'){
                    $payFn = 'mtnRwPay';
                    $instructions = "\n".lang('app.dialPay',['*182*7*1#'], $lang);
                }
                else if (getenv('app.country') =='DRC'){
                    set_time_limit(120);
                    return $this->drcPay($msisdn, $amount, $reference_id, $input->currency, $paymentId);
                }
                if ($this->$payFn($msisdn, $amount, $reference_id, $memberData['names'], $created_at,$paymentId)) {
                    return $this->response->setStatusCode(200)
                        ->setJSON(['status' => 200, 'message' => lang('app.paymentRequestSent', [$instructions], $lang),
                            'trxId' => $reference_id]);
                }
            } catch (\Exception $e) {
                log_message('error', lang('app.paymentGatewayFailed') . $e->getMessage());
                return $this->response->setStatusCode(500)
                    ->setJSON(['status' => 500, 'message' => lang('app.paymentFailedTryAgainLater')]);
            }

        } catch (\Exception $e) {
            log_message('error', lang('app.systemErrorAppPayment') . $e->getMessage());
            return $this->response->setStatusCode(500)
                ->setJSON(['status' => 500, 'message' => lang('app.paymentFailedAgainLater')]);
        }
    }

    /**
     * @throws \Exception
     */
    private function payFastHub($msisdn, $amount, $reference_id, $names, $created_at, $paymentId = null): bool
    {
//        $url = "http://161.35.192.201/call_back";
        $url = base_url('call_back/tz_fh');
        $urlEncode = urlencode($url);
        $amountEncode = urlencode($amount);
        $msisdnEncode = urlencode($msisdn);
        $referenceIdEncode = urlencode($reference_id);
        $doneAtEncode = urlencode($created_at);

        $string = "amount=" . $amountEncode . "&channel=1161&callback_url=" . $urlEncode . "&recipient=" . $msisdnEncode . "&reference_id=" . $referenceIdEncode . "&trx_date=" . $doneAtEncode;

        log_message('error', 'APP Pay request: ' . $string);
        $hash = hash_hmac('sha256', $string, 'Q6yJIkSpXbHhsTMyDj1W2ziZgjUB5rm7');
        try {
            $this->curl = \Config\Services::curlrequest();
            $response = $this->curl
                ->request("POST", 'https://gcs-api.fasthub.co.tz/fasthub/mobile/money/debitdeposit/api/json', [
                    'auth' => ['api-auth@fasthub.co.tz', '{Y>8By/4L$(RP;aY'],
                    'json' => [
                        "request" => [
                            "hash" => $hash,
                            "channel" => 1161,
                            "callback_url" => $url,
                            "recipient" => $msisdn,
                            "amount" => $amount,
                            "trx_date" => $created_at,
                            "reference_id" => $reference_id,
                            //"reference_id"=>"82063326891735457274",
                            //"bill_ref"=>"2265",
                            "bill_ref" => "9965",
                            "transactionTypeName" => "Debit"
                        ]
                    ]
                ]);
            // return $response;
            $pMdl = new PaymentsModel();
            $ptMdl = new QuickPaymentTransactionModel();
            $bodyData = json_decode((string)$response->getBody());
            log_message('error', 'Response body: ' . $response->getStatusCode() . ' - ' . $response->getBody());
            if ($bodyData == null || !isset($bodyData->isSuccessful)) {
//                log_message('error', 'APP Pay failed: ' . $response->getStatusCode() . ' - ' . $response->getBody());
                if (strpos($reference_id, getenv('app.shortName') . 'QK') !== false || substr($reference_id, 0, 2) == 'QK') {
                    $ptMdl->save(['failed_reason'=>'Payment gateway failed','id'=>$paymentId]);
                } else {
                    $pMdl->save(['failed_reason'=>'Payment gateway failed','id'=>$paymentId]);
                }
                throw new \Exception(lang('app.paymentFailedAgainLater'), 500);
            }

            $isSuccessful = $bodyData->isSuccessful;
            $error_description = $bodyData->error_description;
            if ($isSuccessful == true) {
                return true;
            } else {
                log_message('error', lang('app.paymentNotSucceed') . $response->getBody(),);
            }
            if (strpos($reference_id, getenv('app.shortName') . 'QK') !== false || substr($reference_id, 0, 2) == 'QK') {
                $ptMdl->save(['failed_reason'=>$error_description,'status'=>2,'id'=>$paymentId]);
            } else {
                $pMdl->save(['failed_reason'=>$error_description,'status'=>2,'id'=>$paymentId]);
            }
            throw new \Exception(lang('app.paymentFailed') . $error_description, 500);
        } catch (\Exception $e) {
            log_message('error', lang('app.paymentGatewayFailed') . $e->getMessage());
            throw new \Exception(lang('app.paymentFailedTryAgainLater'), 500);
        }
    }

    /**
     * @throws \Exception
     */
    private function EBL_DRC($msisdn, $amount, $reference_id, $names, $created_at, $paymentId = null): bool
    {
        //no any other extra work need right now
        return true;
    }
    /**
     * @throws \Exception
     */
    public function stanbicUgPay($msisdn, $amount, $reference_id, $names, $created_at,$paymentId): bool
    {
        $sMdl = new SettingModel();
//        $settings = $sMdl->getSettings(['collection_account_number']);
//        if (!isset($settings['collection_account_number']) || strlen($settings['collection_account_number']) < 5){
//            return lang('app.paymentFailedCollection');
//        }
        $pController = new PaymentController();
        $data = [
            'function'=>'cfmsRequestingFundsCollection',
            "payload"=>[
                "requestTime"=>date('Y-m-d H:i:s'),
                "transactionReference"=>$reference_id,
                "mobileNumber"=>$msisdn,
                "amount"=>$amount,
                "name"=>$names,
                "sessionNumber"=>time(),
                "message"=>"CFMS Offering"
            ],
            'transfer' => $pController->getPaymentBankTransfer($paymentId, $amount, $reference_id)
        ];
        log_message('error', 'Pay request: ' . json_encode($data));
        $req = $this->curl->setBody(json_encode($data))->setHeader("Content-Type","application/json")
            ->request("POST",STANBIC_API_URL,
                ['verify' => false,'http_errors' => false]
            );
        $res = $req->getBody();
        $res = str_replace("\n",'',$res);
        log_message('critical', 'Payment result body, ' . $res);
        $pMdl = new PaymentsModel();
        $ptMdl = new QuickPaymentTransactionModel();
        if (($resData = json_decode($res))===false){
            if (strpos($reference_id, getenv('app.shortName') . 'QK') !== false || substr($reference_id, 0, 2) == 'QK') {
                $ptMdl->save(['failed_reason'=>'Payment gateway failed','id'=>$paymentId]);
            } else {
                $pMdl->save(['failed_reason'=>'Payment gateway failed','id'=>$paymentId]);
            }
            log_message('error', 'Payment gateway failed, ' . $res);
            throw new \Exception(lang('app.paymentFailedTryAgainLater'), 500);
        } else if($resData->statusCode!="01"){
//            return lang('app.paymentFailedAgainLater', [], $lang);
            if (strpos($reference_id, getenv('app.shortName') . 'QK') !== false || substr($reference_id, 0, 2) == 'QK') {
                $ptMdl->save(['failed_reason'=>$resData->status,'status'=>2,'id'=>$paymentId]);
            } else {
                $pMdl->save(['failed_reason'=>$resData->status,'status'=>2,'id'=>$paymentId]);
            }
            throw new \Exception($resData->status, 500);
        }
        return true;
    }

    /**
     * @throws \Exception
     */
    public function mtnRwPay($msisdn, $amount, $reference_id, $names, $created_at,$paymentId): bool
    {
        $lang = 'en';
        $url = base_url('call_back/rw_mtn');
        $sMdl = new SettingModel();
        $settings = $sMdl->getSettings(['collection_account_number']);
        if (!isset($settings['collection_account_number']) || strlen($settings['collection_account_number']) < 5){
            return lang('app.paymentFailedCollection', [], $lang);
        }
        $data = [
            "token"=>BESOFT_API_TOKEN,
            "external_transaction_id"=>$reference_id,
            "callback_url"=>$url,
            "debit"=>[
                "phone_number"=>$msisdn,
                "amount"=>$amount,
                "message"=>" CFMS Offering"
            ],
            "transfers" => [
                [
                    "phone_number"=>$settings['collection_account_number'],
                    "amount" => $amount,
                    "message" => $reference_id. " CFMS Offering"
                ]
            ]
        ];
        log_message('error', 'Payment request payload, ' . json_encode($data));
        $req = $this->curl->setBody(json_encode($data))->setHeader("Content-Type","application/json")
            ->request("POST",BESOFT_API_URL,
                ['verify' => false,'http_errors' => false]
            );
        $res = $req->getBody();
        log_message('error', 'Payment result, ' . $res);

        $pMdl = new PaymentsModel();
        $ptMdl = new QuickPaymentTransactionModel();
        if (($resData = json_decode($res))===false){
            if (strpos($reference_id, getenv('app.shortName') . 'QK') !== false || substr($reference_id, 0, 2) == 'QK') {
                $ptMdl->save(['failed_reason'=>'Payment gateway failed','id'=>$paymentId]);
            } else {
                $pMdl->save(['failed_reason'=>'Payment gateway failed','id'=>$paymentId]);
            }
            log_message('error', 'Payment gateway failed, ' . $res);
            throw new \Exception(lang('app.paymentFailedTryAgainLater'), 500);
        }else if($resData->status_code>300){
             if (strpos($reference_id, getenv('app.shortName') . 'QK') !== false || substr($reference_id, 0, 2) == 'QK') {
                $ptMdl->save(['failed_reason'=>$resData->message,'status'=>2,'id'=>$paymentId]);
            } else {
                $pMdl->save(['failed_reason'=>$resData->message,'status'=>2,'id'=>$paymentId]);
            }
            throw new \Exception($resData->message, 500);
        }
        return true;
    }


    public function drcPay($msisdn, $amount, $reference_id, $currency,$paymentId){
        $res = $this->curl
            ->request("POST", 'https://api.cashnayo.com/v1/', [
                'json' => [                        
                    'action' => "pay",
                    'auth' => "db80838fa6b781aada4084025e7a7acb5b88d91a",
                    'tx' => $reference_id,
                    'amo' => $amount,
                    'currency' => $currency,
                    // 'names' => "Kayitare prince",
                    'tel' => $msisdn,
                    // 'email' => "kayitareprince20@gmail.com",
                    'callback' => base_url("call_back/drc"),
                    
                ]
            ]);

        $bodyData = json_decode((string)$res->getBody());
        $pMdl = new PaymentsModel();
        $ptMdl = new QuickPaymentTransactionModel();

        if (($resData = $bodyData) === false){
             if (strpos($reference_id, getenv('app.shortName') . 'QK') !== false || substr($reference_id, 0, 2) == 'QK') {
                $ptMdl->save(['failed_reason'=>'Payment gateway failed','id'=>$paymentId]);
            } else {
                $pMdl->save(['failed_reason'=>'Payment gateway failed','id'=>$paymentId]);
            }
            log_message('error', 'Payment gateway failed, ' . $res);
            throw new \Exception(lang('app.paymentFailedTryAgainLater'), 500);
        }else if(intval($resData->code) != 110){
            log_message('error', 'Payment gateway failed2, ' . $bodyData->status);
             if (strpos($reference_id, getenv('app.shortName') . 'QK') !== false || substr($reference_id, 0, 2) == 'QK') {
                $ptMdl->save(['failed_reason'=>$resData->status,'status'=>2,'id'=>$paymentId]);
                return $this->response->setStatusCode(400)->setJSON(['status' => 400, 'message' => $resData->status]);
            } else {
                $pMdl->save(['failed_reason'=>$resData->status,'status'=>2,'id'=>$paymentId]);
                return $this->response->setStatusCode(400)->setJSON(['status' => 400, 'message' => $resData->status]);
            }
            
            return $resData->status;
        }
        else{
            sleep(10);
            $res =  $this->checkDrcStatus($reference_id);
            if($res == "success")
                return $this->response->setStatusCode(200)->setJSON(['status' => 200, 'message' => "success"]);
            else
                return $this->response->setStatusCode(400)->setJSON(['status' => 400, 'message' => $res]);
           
        }
        return true;
    }

    public function checkDrcStatus($reference_id){
        $res = $this->curl
        ->request("POST", 'https://api.cashnayo.com/v1/', [
            'json' => [                        
                'action' => "status",
                'auth' => "db80838fa6b781aada4084025e7a7acb5b88d91a",
                'tx' => $reference_id,
            ]
        ]);
        log_message('error', 'INFO REF .., ' . $res->getBody());
        $bodyData = json_decode((string)$res->getBody());
        log_message('error', 'INFO RETURNED .., ' . $bodyData->state);
        if($bodyData->state == "Completed"){
            return  "success";
        }
        else if($bodyData->state == "failed"){
            return "failed";
        }
        else {
            sleep(5);
            $this->checkDrcStatus($reference_id);
        }
                 
    }
        


    public function paymentHistory($code = null, $range = null): ResponseInterface
    {
        $paymentsModel = new PaymentsModel();
        $result = $paymentsModel->select('DATE(payments.created_at) as date')
            ->join('members m', 'payments.memberId = m.id')
            ->where('m.code', $code)
            ->where('payments.status', '1');
            if($range != null){
                $startDate = explode(' ', $range)[0];
                $endDate = isset(explode(' ', $range)[1]) ? explode(' ', $range)[1] : "" ;
                $result->where("DATE(payments.created_at) BETWEEN '{$startDate}' AND '{$endDate}'");
            }
            $result = $result->groupBy('DATE(created_at)')
            ->get()->getResultArray();

        if (!empty($result)) {
            return $this->response->setJSON($result);
        } else {
            $result['status'] = 404;
            $result['message'] = lang('app.noPaymentHistoryFound');
            $result['data'] = null;
            return $this->response->setStatusCode(404)->setJSON($result);
        }
    }


    public function exportExcel($data){
        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->getCell('A1')->setValue('Type');
        $worksheet->getCell('B1')->setValue('Amount');
        $worksheet->getCell('C1')->setValue('Phone');
        $worksheet->getCell('D1')->setValue('Church');
        $worksheet->getCell('E1')->setValue('Ref.');
        $worksheet->getCell('F1')->setValue('Date');
        $styleArray = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_HAIR,
                    'color' => ['argb' => 'FFFFFFFF']
                ]
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF058e8c'],
            ],
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
                'size' => $spreadsheet->getDefaultStyle()->getFont()->setSize(10)
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ];
        $i = 2;
        foreach($data as $item){
            $worksheet->getCell('A'.$i)->setValue($item["title"]);
            $worksheet->getCell('B'.$i)->setValue($item["amount"]);
            $worksheet->getCell('C'.$i)->setValue($item["phone"]);
            $worksheet->getCell('D'.$i)->setValue($item["church_name"]);
            $worksheet->getCell('E'.$i)->setValue($item["ref_no"]);
            $worksheet->getCell('F'.$i)->setValue($item["paid_at"]);
        }
        $worksheet->getColumnDimension('A')->setAutoSize(true);
        $worksheet->getColumnDimension('B')->setAutoSize(true);
        $worksheet->getColumnDimension('C')->setAutoSize(true);
        $worksheet->getColumnDimension('D')->setAutoSize(true);
        $worksheet->getColumnDimension('E')->setAutoSize(true);
        $worksheet->getColumnDimension('F')->setAutoSize(true);
        $writer = new Xlsx($spreadsheet);  
        $writer->save('tithes.xlsx');
        return file_exists('tithes.xlsx')? "tithes.xlsx" : "error";
    }


    public function onePaymentHistory($code = null, $export = false, $date = null): ResponseInterface
    {
        $paymentsModel = new PaymentsModel();
        if(!$export)
            $date = $this->request->getPost('date');
        $result = $paymentsModel->select('payments.trxId, po.amount, payments.paid_at,payments.created_at, 
        payments.status,payments.phone,payments.ref_no,of.title,e.title as church_name')
            ->join('payments_offerings po', 'payments.id = po.payment_id')
            ->join('offerings of', 'po.offeringId = of.id')
            ->join('members m', 'payments.memberId = m.id')
            ->join('entity e', 'payments.churchId = e.id')
            ->where('m.code', $code)
            ->where('payments.status', '1')
            ->where('DATE(payments.created_at)', $date)
            ->get()->getResultArray();

        if (!empty($result)) {
            if($export){
                $file_name = $this->exportExcel($result);
                return $this->response->download($file_name, file_get_contents($file_name));
            }
            else
                return $this->response->setJSON($result);
        } else {
            $result['status'] = 404;
            $result['message'] = lang('app.noPaymentHistoryFound');
            $result['data'] = null;
            return $this->response->setStatusCode(404)->setJSON($result);
        }

    }

    public function member(): ResponseInterface
    {
        $mdl = new MemberModel();
        $entityModel = new EntityModel();
        $entityTypeModel = new EntityTypeModel();
        $code = $_GET['code'];
        $level = $entityTypeModel->selectMax('level')->first();
        $title = $entityTypeModel->select('title')->where('level <=', $level)->orderBy('level', 'DESC')->get()->getResultArray();

        $join = "";
        $query = "";

        $select = "SELECT m.*, e" . $title[0]['title'] . ".code as " . strtoupper($title[0]['title']) . "Code, e" . $title[0]['title'] . ".created_at";
        for ($i = 0; $i <= $level['level'] - 1; $i++) {
            $select .= ", e" . $title[$i]['title'] . ".title as `" . strtoupper($title[$i]['title']) . "` ";
            if ($i + 1 <= $level['level'] - 1) {
                $join .= " INNER JOIN entity e" . $title[$i + 1]['title'] . " ON e" . $title[$i]['title'] . ".parentId=e" . $title[$i + 1]['title'] . ".id";
            } else {
                continue;
            }
        }
        $phone = validSMSNumber($code);
        $select .= "FROM entity e" . $title[0]['title'];
        $join .= " inner join members m on m.entity_id = e" . $title[0]['title'] . ".id";
        $query .= $select;
        $query .= $join;
        $query .= " where m.code ='$code' OR phone like '%$phone'";
        $result['status'] = 200;
        $result['message'] = lang('app.memberFound');
        $result['data'] = $entityModel->db->query($query)->getResultArray();
        if (empty($result['data']) || !isset($result['data'][0]['CHURCHCode'])) {
            $result['status'] = 404;
            $result['message'] = lang('app.memberNotFound');
            return $this->response->setStatusCode(404)->setJSON($result);
        }
        $result['church_details'] = $this->checkChurch($result['data'][0]['CHURCHCode']);
        if (empty($result['church_details'])) {
            $result['status'] = 404;
            $result['message'] = lang('app.churchNotFound');
            return $this->response->setStatusCode(404)->setJSON($result);
        }
        return $this->response->setJSON($result);
    }

    public function quickPaymentAdd(): ResponseInterface
    {
        $this->_secure();
        $mdl = new quickPayment();
        $uMdl = new UsersModel();
        $pMdl = new PaymentsModel();
        $pOMdl = new PaymentOfferingsModel();
        $mMdl = new MemberModel();
        $input = json_decode(file_get_contents("php://input"));

        if (!isset($this->accessData->uid) || !isset($input->phone) || !isset($input->offerings)) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 500, 'message' => 'Invalid data, please try again']);
        }
        if (strlen($input->phone) < 10) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 500, 'message' => 'Invalid phone number']);
        }
        $phone = validSMSNumber($input->phone);
        $offeringsArray = $input->offerings;
        $sum = 0;
        $churchId = $uMdl->select('et.id,et.title')
            ->join('entity et', 'et.id = users.entityId')
            ->where('users.id', $this->accessData->uid)
            ->first();
        if ($churchId == null) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 500, 'message' => 'Invalid church for logged in user', "id" => $this->accessData->uid]);
        }
        
        $mMdl = new MemberModel();
        $settingsMdl = new SettingModel();
        $smsMdl = new SmsSentModel();
        $offMdl = new OfferingsModel();
        $member = $mMdl->select("names,id")->where('phone', $phone)->asObject()->first();
        if ($member == null) {
            $memberName = "Member";
            $memberId = null;
        } else {
            $memberName = $member->names;
            $memberId = $member->id;
        }
        $lang = "en";
        if (getenv('app.country') == 'TZ'){
            $lang = "sw";
        } else if (getenv('app.country') == 'DRC'){
            $lang = "fr";
        }
        try {
            foreach ($offeringsArray as $offer) {
                $sum += $offer->amount;
                $title = $offMdl->select("title")->where('id', $offer->id)->asObject()->first();
                $offer->title = $title->title;
            }
            $bMdl = new AccountNumberModel();
            $bankData = $bMdl->select('id')->where('entityId',$churchId['id'])->asObject()->first();
            if ($bankData == null){
                return $this->response->setStatusCode(400)
                    ->setJSON(['status' => 400, 'message' => 'Church bank details not found, contact admin']);
            }
            $offerings = json_encode($offeringsArray);
            $sessionId = $this->accessData->uid . '' . time();
            $quickId = $mdl->insert([
                'phone' => $phone,
                'offerings' => $offerings,
                'churchId' => $churchId['id'],
                'totalAmount' => $sum,
                'operator' => $this->accessData->uid,
                'sessionId' => $sessionId,
                'status' => '0',
            ]);
            $reference_id = getenv('app.shortName') . 'QK' . $quickId;

            $paymentId = $pMdl->insert([
                'memberId' => $memberId,
                'phone' => $phone,
                'amount' => $sum,
                'churchId' => $churchId['id'],
                'sessionId' => $sessionId,
                'trxId' => $reference_id,
                'type' => 1,
                'paid_at' => date('Y-m-d H:i:s'),
                'status' => 1
            ]);


            if ($paymentId === false) {
                return $this->response->setStatusCode(500)
                    ->setJSON(['status' => 500, 'message' => lang('app.paymentFailedAgainLater')]);
            }
            $offeringsData = [];
            foreach ($offeringsArray as $offer) {
                $sum += $offer->amount;
                $oMdl = new OfferingsModel();
                $offeringsData[] = $oMdl->select($offer->amount . ' as amount,offerings.title,offerings.translation,"'.$reference_id.'" as trxId')
                    ->where('id', $offer->id)->get(1)->getRow();
                $pOMdl->save(['payment_id' => $paymentId, 'offeringId' => $offer->id, 'amount' => $offer->amount]);
            }
            if (strlen($phone) >=10) {
                $settings = $settingsMdl->getSettings(['sms_sender_name']);
                $message = generateOfferingSMS($lang, $memberName, $churchId['title'], $offeringsData);
//            log_message("critical", "MSG: " . $message);
                $status = 0;
                $errorMsg = null;

                if ($this->sendSMS($phone, $message, $res, $settings['sms_sender_name'])) {
                    $status = 1;
                } else {
                    $errorMsg = $res['content'];
                }
                $smsMdl->save(['phone' => $phone, 'type' => 3, 'typeId' => $quickId, 'smsCount' => countSMS($message)
                    ,'message'=>$message, 'memberId' => $member->id ?? null, 'status' => $status, 'errorMessage' => $errorMsg]);
            }
            return $this->response->setJSON(['status' => 200, 'message' => 'Quick payment saved', "reference" => $reference_id ]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(['status' => $e->getCode(), 'message' => 'System error: ' . $e->getMessage()]);
        }
    }

    public function quickPaymentReport(): ResponseInterface
    {
        $this->_secure();
        $mdl = new quickPayment();
        if (!isset($this->accessData->uid)) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 500, 'message' => 'Invalid data, please try again']);
        }
        $result = $mdl->select('SUM(totalAmount) as Amount, COUNT(id) `Count`,DATE(created_at) Date,status')
            ->where("operator", $this->accessData->uid)
            ->groupBy('date_format(created_at, "%Y%m%d")')
            ->groupBy('status')
            ->get()->getResultArray();
        return $this->response->setJSON($result);
    }

    public function quickPaymentPay(): ResponseInterface
    {
        $this->_secure();
        $mdl = new quickPayment();
        $pMdl = new PaymentsModel();
        $input = json_decode(file_get_contents("php://input"));
        if (!isset($this->accessData->uid) || !isset($input->phone) || !isset($input->date)) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 500, 'message' => 'Invalid data, please try again']);
        }
        $phone = validSMSNumber($input->phone);
        $amount = $input->amount;
        $currency = $input->currency;
        $date = $input->date;
        $result = $mdl->select('SUM(quick_payments.totalAmount) as amount,coalesce(quick_payments.trxId,"") as trxId')
            ->where("quick_payments.operator", $this->accessData->uid)
            ->where('date_format(quick_payments.created_at, "%Y-%m-%d")', $date)
            ->where('quick_payments.status', 0)
            ->get()->getRow();
        if ($result == null) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 500, 'message' => 'No pending quick payment found on ' . $date]);
        } else if ($result->amount != $amount) {
            return $this->response->setStatusCode(500)->setJSON(
                ['status' => 500, 'message' => 'entered amount is not equal to the pending amount of ' . number_format($result->amount)]
            );
        }
//        $results = $pMdl->select('payments.id, qp.id as qpId')
//                        ->join('quick_payments qp', 'qp.sessionId = payments.sessionId')
//                        ->where("qp.operator", $this->accessData->uid)
//                        ->where('date_format(payments.created_at, "%Y-%m-%d")', $date)
//                        ->where('payments.status', 0)
//                        ->get()->getResult();
//        foreach ($results as $item) {
//
//            $offs = json_decode($item->offerings);
//            $amount += $item->totalAmount;
//            try {
//                $pMdl->save([
//                    'id' => $item->id,
//                    'status' => 1
//                ]);
//                $mdl->save([
//                    'id' => $item->qpId,
//                    'status' => 1
//                ]);
//            } catch (\Exception $e) {
//                return $this->response->setStatusCode(500)->setJSON(['status' => $e->getCode(), 'message' => 'System error: ' . $e->getMessage()]);
//            }
//        }
        try {
//            $memberData = $mMdl->select('id,names')->where('phone', $item->phone)->get(1)->getRow();
            $reference_id = empty($result->trxId)?getenv('app.shortName') . 'QK' . $this->accessData->uid.'T'.time():$result->trxId;
            $transaction_reference_id = 'QK' .strtoupper(substr(md5(microtime()),rand(0,26),5));  // getenv('app.shortName') . 'QK' . $this->accessData->uid.'T'.time();
            //check transactions
            $tMdl = new QuickPaymentTransactionModel();
            log_message('critical',$result->trxId.' - '.$reference_id.' - '.$transaction_reference_id);
            $payFn = "payFastHub";
            $instructions = '';
            $paymentMethode = 'FASTHUB';
            if (getenv('app.country') =='UG'){
                $payFn = 'stanbicUgPay';
                $paymentMethode = 'STANBIC_FLEXIPAY';
            } else if (getenv('app.country') =='RW'){
                $payFn = 'mtnRwPay';
                $paymentMethode = 'MOPAY';
                $instructions = "\n".lang('app.dialPay',['*182*7*1#']);
            } else if (getenv('app.country') =='DRC'){
                if (isset($input->paymentMethod) && $input->paymentMethod == 'EBL'){
                    //Equity bill payment gateway
                    $payFn = 'EBL_DRC';
                    $paymentMethode = 'EBL';
                } else{
                    //currently disable other payment mode
                    return $this->response->setStatusCode(500)->setJSON(
                        ['status' => 500, 'message' => 'Oops, The payment mode selected is not available']
                    );
                }
            }
            $paymentId = $tMdl->insert(['trxId'=>$reference_id, 'newTrxId'=>$transaction_reference_id,'amount'=>$amount
                ,'operator'=>$this->accessData->uid,'paymentMethode'=>$paymentMethode, 'currency'=>$currency, 'status'=>0]);
            $mdl->set('trxId', $reference_id)
                ->where("operator", $this->accessData->uid)
                ->where('date_format(created_at, "%Y-%m-%d")', $date)
                ->where('status', 0)->update();
            $created_at = date('Y-m-d H:i:s');

//            $this->quickPayment($phone, $amount, $reference_id, 'Quick-payment', $created_at);

            $this->$payFn($phone, $amount, $transaction_reference_id, 'Quick payment', $created_at, $paymentId);
            $msg = lang('app.paymentRequestSent',[$instructions]);
            if($payFn == 'EBL_DRC'){
                $msg = lang('app.ebl_message');
            }
            return $this->response->setStatusCode(200)
                ->setJSON(['status' => 200, 'message' => $msg,
                    'data' => null,'reference_no' => $transaction_reference_id]);
        } catch (\Exception $e) {
            log_message('error', "QUICK - " . lang('app.paymentGatewayFailed') . $e->getMessage());
            return $this->response->setStatusCode(500)
                ->setJSON(['status' => 500, 'message' => $e->getMessage(),
                    'data' => null]);
        }
    }

    /**
     * @throws \Exception
     */
    private function ebl_authentication($input): void
    {
        //we will change to dynamic mode (db) later
        if (!isset($input->username) || !isset($input->password)){
            throw new \Exception("Invalid request, missing some required data");
        }
        if(getenv('ebl.username')===$input->username){
            if (!password_verify($input->password, getenv('ebl.password'))){
                throw new \Exception("Invalid authentication");
            }
        } else {
            throw new \Exception("Invalid authentication");
        }
    }

    public function validate_bill(): ResponseInterface
    {
        try{
            $input = json_decode(file_get_contents("php://input"));
            if (!isset($input->billNumber)){
                log_message('critical', json_encode($input));
                throw new \Exception("Invalid request, missing some required data");
            }
            $this->ebl_authentication($input);
            $mdl = new QuickPaymentTransactionModel();
            $data = $mdl->select('quick_payment_transaction.*,u.names')
                ->join('users u', 'u.id = quick_payment_transaction.operator')
                ->where('quick_payment_transaction.newTrxId',$input->billNumber)
                ->asObject()->first();
            if ($data == null){
                throw new \Exception("Bill number not found");
            } else if ($data->status == '1'){
                $extra = json_decode($data->extra);
                $paidBy = isset($extra->debitcustname)?' by '.$extra->debitcustname:'';
                throw new \Exception("Bill number already paid on {$data->paid_at}{$paidBy}.");
            }
            return $this->response->setJSON([
                'amount'=>$data->amount,
                'billName'=>'CFMS Offerings',
                'billNumber'=>$data->newTrxId,
                'billerCode'=>$data->id,
                'createdOn'=>date('Y-m-d',strtotime($data->created_at)),
                'currencyCode' => $data->currency,
                'customerName'=>$data->names,
                'customerRefNumber'=>'CFMS'.$data->operator,
                'type'=>'0',
            ]);
        } catch (\Exception $e){
            return $this->response->setJSON(['amount'=>0,'billNumber'=>null,'billName'=>null,'description'=>$e->getMessage(),'type'=>'0']);
        }
    }

    public function payment_notification(): ResponseInterface
    {
        try{
            $input = json_decode(file_get_contents("php://input"));
            if (!isset($input->billNumber) || !isset($input->billAmount) || !isset($input->bankreference)){
                log_message('critical', json_encode($input));
                throw new \Exception("Invalid request, missing some required data");
            }
            $this->ebl_authentication($input);
            $mdl = new QuickPaymentTransactionModel();
            $data = $mdl->select('quick_payment_transaction.*,u.names')
                ->join('users u', 'u.id = quick_payment_transaction.operator')
                ->where('quick_payment_transaction.newTrxId',$input->billNumber)
                ->asObject()->first();
            if ($data == null){
                throw new \Exception("Bill number not found");
            } else if ($data->status == '1'){
                throw new \Exception("DUPLICATE TRANSACTION");
            }else if ((int)$data->amount != (int)$input->billAmount){
                throw new \Exception("Amount not match");
            }
            $extra = [
                'paymentMode'=>$input->paymentMode,
                'phoneNumber'=>$input->phonenumber,
                'debitAccount'=>$input->debitaccount,
                'debitCustomerName'=>$input->debitcustname
            ];
            $paid_date = date('Y-m-d H:i:s', strtotime($input->transactionDate));
            $mdl->save(['ref_no'=>$input->bankreference,'paid_at'=>$paid_date
                ,'extra'=>json_encode($extra),'id'=>$data->id,'status'=>1]);

            $qMdl = new quickPayment();
                $qMdl->set('status', 1)
                    ->set('ref_no', $input->bankreference)
                    ->set('paid_at', $paid_date)
                    ->where("trxId", $data->trxId)
                    ->update();
                $mdl->db->query("update payments p inner join quick_payments q on q.sessionId = p.sessionId set p.ref_no=q.ref_no where q.status=1 and p.ref_no is null;");

            return $this->response->setJSON(['responseCode'=>'OK','responseMessage'=>'SUCCESSFUL']);
        } catch (\Exception $e){
            return $this->response->setJSON(['responseCode'=>'OK','responseMessage'=>$e->getMessage()]);
        }
    }
}
