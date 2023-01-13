<?php

use App\Controllers\Home;
use App\Controllers\PaymentController;
use App\Models\EntityModel;
use App\Models\MemberModel;
use App\Models\OfferingsModel;
use App\Models\PaymentOfferingsModel;
use App\Models\PaymentsModel;
use App\Models\SettingModel;
use App\Models\SmsSentModel;
use App\Models\USSDFlow;
use CodeIgniter\Format\Exceptions\FormatException;
use const App\Controllers\BESOFT_API_URL;

if (!function_exists('buildTree')) {
    /**
     * function buildTree
     * @param array $elements
     * @param array $options ['parent_id_column_name', 'children_key_name', 'id_column_name']
     * @param int $parentId
     * @return array
     */
    function buildTree(array           $elements, array $options = [
        'parent_id_column_name' => 'parent_id',
        'children_key_name' => 'children',
        'id_column_name' => 'id'], int $parentId = 0): array
    {
        $branch = array();
        foreach ($elements as $element) {
            if ($element[$options['parent_id_column_name']] == $parentId) {
                $children = buildTree($elements, $options, $element[$options['id_column_name']]);
                if ($children) {
                    $element[$options['children_key_name']] = $children;
                }
                $branch[] = $element;
            }
        }
        return $branch;
    }
}
if (!function_exists('buildLevelTree')) {
    /**
     * function buildLevelTree
     * @param array $elements
     * @param array $options ['parent_id_column_name', 'children_key_name', 'id_column_name']
     * @param int $parentId
     * @return array
     */
    function buildLevelTree(array      $elements, array $options = [
        'parent_id_column_name' => 'level',
        'children_key_name' => 'children',
        'id_column_name' => 'id'], int $parentId = 0): array
    {
        $branch = array();
        foreach ($elements as $element) {
            if (($element[$options['parent_id_column_name']] - 1) == $parentId) {
                $children = buildLevelTree($elements, $options, $element[$options['parent_id_column_name']]);
                if ($children) {
                    $element[$options['children_key_name']] = $children;
                }
                if (isset($element['fields'])) {
                    $element['fields'] = json_decode($element['fields']);
                }
                $branch[] = $element;
            }
        }
        return $branch;
    }
}

if (!function_exists('sanitizeTxt')) {
    function sanitizeTxt($txt)
    {
        return empty($txt) ? "" : trim($txt);
    }
}
if (!function_exists('paymentStatusToText')) {
    function paymentStatusToText($status)
    {
        switch ($status) {
            case '0':
                return 'pending';
            case '1':
                return 'completed';
            case '2':
                return 'failed';
            default:
                return $status;
        }
    }
}
if (!function_exists('generateMemberCode')) {
    function generateMemberCode()
    {
        $mdl = new MemberModel();
        $lastKnownID = $mdl->select('code')->orderBy('code', 'desc')->get(1)->getRow();
        $lastKnownID = $lastKnownID == null || empty($lastKnownID->code) ? 0 : $lastKnownID->code;
        return $newId = str_pad($lastKnownID + 1, 7, "0", STR_PAD_LEFT);
    }
}
if (!function_exists('validSMSNumber')) {
    function validSMSNumber($phone): string
    {
        $sMdl = new \App\Models\SettingModel();
        $countryCode = $sMdl->getSettings(['countryCode'])['countryCode'];
        if (preg_match("[^\+$countryCode|$countryCode]", $phone)  && strlen($phone) > 10) {
            return trim($phone, "+");
        } else {
            if (strpos($phone, '0') !== 0) {
                return $countryCode . $phone;
            } else {
                return $countryCode . substr($phone, 1);
            }
        }
    }
}
if (!function_exists('countSMS')) {
    function countSMS($message): string
    {
        return (int)ceil(strlen($message) / 160);
    }
}
if (!function_exists('generateOfferingSMS')) {
    function generateOfferingSMS($lang, $name, $church, $trxId, $date = null): string
    {
        if (is_array($trxId)) {
            $offerings = $trxId;
        } else {
            $paymentMdl = new PaymentsModel();
            $offerings = $paymentMdl->select('po.amount, o.title,o.translation')
                ->join('payments_offerings po', 'payments.id = po.payment_id')
                ->join("offerings o", "o.id = po.offeringId")
                ->where(['trxId' => $trxId])->where(['payments.amount!=' => 0])->get()->getResult();
        }
        $offMsg = "";
        foreach ($offerings as $offering) {
            if (is_array($trxId)) {
                $trxId = $offering->trxId;
            }
            $texts = json_decode($offering->translation);
            $text = $texts->$lang ?? $offering->title;
            $offMsg .= $text . ':' . number_format($offering->amount) . ', ';
        }
        $date = $date ?? date("Y-m-d H:i:s");
        if ($lang == "rw") {
            $message0 = $name . ",itorero rya " . $church . ", ryakiriye neza " . $offMsg . " Imana iguhe umugisha. " . $date . "\nKode:{$trxId}";
        } elseif ($lang == "fr") {
            $message0 = $name . " l’eglise de " . $church . ", a recue avec success votre " . $offMsg . " Que Dieu vous benisse. " . $date . "\nCode:{$trxId}";
        } elseif ($lang == "sw") {
            $message0 = $name . ", sadaka yako ya " . $offMsg . " imepokelewa kikamilifu kwa Kanisa la " . $church . ", Mungu akubariki. " . $date . "\nCode:{$trxId}";
        } else {
            $message0 = $name . ", your offering of " . $offMsg . " has been successfully received by " . $church . " church.May God bless you. " . $date . "\nCode:{$trxId}";
        }
        return $message0;
    }
}
if (!function_exists('generateRegistrationSMS')) {
    /**
     * @param string $phone
     * @param $lang string
     * @param $type string self or registrar
     * @return string
     * @throws Exception
     */
    function generateRegistrationSMS(string $phone, string $lang, string $type = 'self'): string
    {
        $memberMdl = new MemberModel();
        $memberBuilder = $memberMdl->select('members.id,names,members.code,entity_id,defaultLanguage,members.status,members.phone,e.title,members.code as member_code,e.code,networkOperator')
            ->join('entity e', 'e.id = members.entity_id', 'LEFT');

        if ($type != 'self') {
            $memberBuilder->where('registrarPhone', $phone)
                ->where('phone', null);
        } else {
            $memberBuilder->where('phone', $phone);
        }

        $member = $memberBuilder->orderBy('id', 'DESC')
            ->asObject()->first();
        if ($member == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }

        if ($lang == "rw") {
            $message0 = $member->names . ", Kwiyandikisha kuri SDA CFMS byagenze neza.\nItorero: " . $member->title . ", Kode:" . $member->member_code . ", Murakoze. \n" . date("Y-m-d H:i:s");
        } elseif ($lang == "fr") {
            $message0 = $member->names . ", L'inscription sur SDA CFMS est terminée.\nEglise:" . $member->title . ", code membre:" . $member->member_code . ", Merci.\n" . date("Y-m-d H:i:s");
        } elseif ($lang == "sw") {
            $message0 = $member->names . ", Usajili kwenye SDA CFMS umekamilika.\nKanisa:" . $member->title . ",namba ya usajili:" . $member->member_code . ", Asante.\n" . date("Y-m-d H:i:s");
        } else {
            $message0 = $member->names . ", Registration on SDA CFMS is completed.\nChurch:" . $member->title . ", member code: " . $member->member_code . ", Thank you.\n" . date("Y-m-d H:i:s");
        }
        return $message0;
    }
}
if (!function_exists('TotalAmount')) {
    function TotalAmount($sessionid)
    {
        $paymentMdl = new PaymentsModel();
        return $paymentMdl->select('sum(amount) as amount')->where(['sessionId' => $sessionid])->get(1)->getRow()->amount;
    }
}
if (!function_exists('PaymentUpdate')) {
    /**
     * @throws Exception
     */
    function PaymentUpdate($field, $input, $sessionid): bool
    {
        $mdl = new PaymentsModel();
        $id = $mdl->select('id')->where('sessionId', $sessionid)
            ->orderBy('id', 'desc')->get(1)->getRow();
        try {
            return $mdl->save(['id' => $id->id, $field => $input]);
        } catch (Exception $e) {
            if ($e->getCode() == 1062) {
                throw new Exception("Offering already selected\n 0. Back", 400);
            }
            throw $e;
        }
    }
}
if (!function_exists('UpdateOffering')) {
    /**
     * @throws Exception
     */
    function UpdateOffering($language, $input, $sessionid): string
    {
        $offMdl = new OfferingsModel();
        $offerings = $offMdl->orderBy('id', 'asc')->get()->getResult();
        $count = 1;
        $discovered = "empty";
        foreach ($offerings as $offering) {
            if ($input == $count) {
                $translation = json_decode($offering->translation);
                $discovered = $translation->$language ?? $translation->en;
                PaymentUpdate('offeringId', $offering->id, $sessionid);
            }
            $count++;
        }
        return $discovered;
    }
}
if (!function_exists('FeeAndAmount')) {
    function FeeAndAmount($sessionid)
    {
        $paymentMdl = new PaymentsModel();
        $variables = $paymentMdl->select('payments.amount,o.title')
            ->join('payments_offerings po', 'payments.id = po.payment_id')
            ->join('offerings o', 'o.id = po.offeringId')
            ->where(['sessionId' => $sessionid])->get()->getResult();

        $total = 0;
        $data2 = array();
        foreach ($variables as $key => $variable) {
            $total = $total + $variable->amount;
            $data2[] = "\n" . $variable->title . ": " . $variable->amount;
        }
        return implode($data2);
    }
}
if (!function_exists('payment')) {
    function payment($msisdn, $memberId, $churchId, $payment_session)
    {
        $payMdl = new PaymentsModel();
        $data = ['phone' => $msisdn, 'sessionId' => $payment_session, 'memberId' => $memberId
            , 'churchId' => $churchId];
        try {
            return $payMdl->insert($data);
        } catch (ReflectionException $e) {
            return false;
        }
    }
}

if (!function_exists('saveFlow')) {
    function saveFlow($language, $message, $input, $session, $msisdn, $level, $sublevel1): bool
    {
        $ussdFlow = new \App\Models\UssdFlowModel();
        $data = ['language' => $language, 'message' => $message, 'input' => $input, 'session' => $session
            , 'telephone' => $msisdn, 'level' => $level, 'sublevel1' => $sublevel1];
//        $ussdFlow->language = $language;
//        $ussdFlow->message = $message;
//        $ussdFlow->input = $input;
//        $ussdFlow->session = $session;
//        $ussdFlow->telephone = $msisdn;
//        $ussdFlow->level = $level;
//        $ussdFlow->sublevel1 = $sublevel1;
        try {
            $ussdFlow->save($data);
        } catch (Exception $e) {
            log_message('error', json_encode($e));
            return false;
        }

        return true;
    }
}
if (!function_exists('array_to_xml')) {

    /**
     * A recursive method to convert an array into a valid XML string.
     *
     * Written by CodexWorld. Received permission by email on Nov 24, 2016 to use this code.
     *
     * @see http://www.codexworld.com/convert-array-to-xml-in-php/
     *
     * @param SimpleXMLElement $output
     * @throws Exception
     */
    function array_to_xml(array $data, SimpleXMLElement &$output)
    {
        if (!extension_loaded('simplexml')) {
            // never thrown in travis-ci
            // @codeCoverageIgnoreStart
            throw FormatException::forMissingExtension();
            // @codeCoverageIgnoreEnd
        }

        foreach ($data as $key => $value) {
            $key = normalizeXMLTag($key);

            if (is_array($value)) {
                $subnode = $output->addChild("{$key}");
                array_to_xml($value, $subnode);
            } else {
                $output->addChild("{$key}", htmlspecialchars("{$value}"));
            }
        }
    }

    /**
     * Normalizes tags into the allowed by W3C.
     * Regex adopted from this StackOverflow answer.
     *
     * @param int|string $key
     *
     * @return string
     *
     * @see https://stackoverflow.com/questions/60001029/invalid-characters-in-xml-tag-name
     */
    function normalizeXMLTag($key): string
    {
        $startChar = 'A-Z_a-z' .
            '\\x{C0}-\\x{D6}\\x{D8}-\\x{F6}\\x{F8}-\\x{2FF}\\x{370}-\\x{37D}' .
            '\\x{37F}-\\x{1FFF}\\x{200C}-\\x{200D}\\x{2070}-\\x{218F}' .
            '\\x{2C00}-\\x{2FEF}\\x{3001}-\\x{D7FF}\\x{F900}-\\x{FDCF}' .
            '\\x{FDF0}-\\x{FFFD}\\x{10000}-\\x{EFFFF}';
        $validName = $startChar . '\\.\\d\\x{B7}\\x{300}-\\x{36F}\\x{203F}-\\x{2040}';

        $key = trim($key);
        $key = preg_replace("/[^{$validName}-]+/u", '', $key);
//        $key = preg_replace("/^[^{$startChar}]+/u", 'item$0', $key);
        $key = preg_replace("/^[^{$startChar}]+/u", 'member', $key);

        return preg_replace('/^(xml).*/iu', 'item$0', $key); // XML is a reserved starting word
    }
}

if (!function_exists('saveSessionLang')) {
    /**
     * @throws Exception
     */
    function saveSessionLang(...$args)
    {
        $sessionId = $args[0];
        $lang = $args[1];
        $mdl = new USSDFlow();
        $mdl->set('lang', $lang)->where('sessionId', $sessionId)->update();
    }
}
if (!function_exists('saveMemberLang')) {
    /**
     * @throws Exception
     */
    function saveMemberLang(...$args)
    {
        $lang = $args[1];
        $member = $args[3];
        $mdl = new MemberModel();
        $mdl->save(['defaultLanguage' => $lang, 'id' => $member->id]);
    }
}
if (!function_exists('saveNames')) {
    /**
     * @throws Exception
     */
    function saveNames(...$args)
    {
        $input = $args[1];
        $phone = $args[2];
        $lang = $args[4];
        $member = $args[3];
        if (strlen($input) < 3) {
            throw new Exception(lang('app.invalidName', [], $lang), 400);
        }
        if (strpos($input, ':') !== false) {
            throw new Exception(lang('app.invalidName', [], $lang), 400);
        }
        $mdl = new MemberModel();
        $data = ['names' => $input, 'phone' => $phone, 'defaultLanguage' => $lang, 'code' => generateMemberCode(), 'status' => 2];
        if ($member != null) {
            $data['id'] = $member->id;
            if (!empty($member->code)) {
                unset($data['code']);
            }
        }
        $mdl->save($data);
    }
}
if (!function_exists('saveNamesEdit')) {
    /**
     * @throws Exception
     */
    function saveNamesEdit(...$args)
    {
        $input = $args[1];
        $lang = $args[4];
        $member = $args[3];
        if (strlen($input) < 3) {
            throw new Exception(lang('app.invalidName', [], $lang), 400);
        }
        $mdl = new MemberModel();
        $data = ['names' => $input, 'id' => $member->id,];
        $mdl->save($data);
    }
}
if (!function_exists('saveNamesRegistrar')) {
    /**
     * @throws Exception
     */
    function saveNamesRegistrar(...$args)
    {
        $input = $args[1];
        $phone = $args[2];
        $lang = $args[4];
        if (strlen($input) < 3) {
            throw new Exception(lang('app.invalidName', [], $lang), 400);
        }
        $mdl = new MemberModel();
        $data = ['names' => $input, 'phone' => null, 'registrarPhone' => $phone, 'defaultLanguage' => $lang, 'status' => 2];
        $mdl->save($data);
    }
}
if (!function_exists('saveMemberChurch')) {
    /**
     * @throws Exception
     */
    function saveMemberChurch($phone, $churchCode, $lang, $operator): bool
    {
        $cMdl = new EntityModel();
        $churchData = $cMdl->select('id,code')->where('code', $churchCode)->where('entityType', $_SESSION['churchType'])->asObject()->first();
        if ($churchData == null) {
            throw new Exception(lang('app.churchNotFound', [], $lang), 400);
        }
        $mdl = new MemberModel();
        $filter = getPhoneCodeFilter($phone);
        $member = $mdl->select('members.id,names,members.code,entity_id,defaultLanguage,members.status,members.phone,e.title,e.code,networkOperator')
            ->join('entity e', 'e.id = members.entity_id', 'LEFT')
            ->where($filter)->asObject()->first();
        if ($member != null && !empty($member->names) && !empty($member->entity_id)) {
            //member available redirect to offering
            return false;
        }
        $mData = ['phone' => validSMSNumber($phone), 'code' => generateMemberCode(), 'entity_id' => $churchData->id
            , 'defaultLanguage' => $lang, 'networkOperator' => $operator, 'status' => 1];
        if(getenv("app.country")=="RW"){
            //fetch names from mtn
//            log_message('critical',json_encode($member));
            $ctl = new PaymentController();
            $mData['names'] = $ctl->getUserNameFromMTN($phone);
        }
        if ($member != null && (empty($member->names) || empty($member->entity_id))) {
            if(getenv("app.country")=="RW"){
                $mdl->save(['id' => $member->id, 'entity_id' => $churchData->id, 'names' => $mData['names']
                    , 'defaultLanguage' => $lang, 'networkOperator' => $operator, 'status' => 1]);
                return false;
            } else {
                $mdl->save(['id' => $member->id, 'entity_id' => $churchData->id
                    , 'defaultLanguage' => $lang, 'networkOperator' => $operator, 'status' => 1]);
                return true;
            }

        }
        $mdl->insert($mData);
        if(getenv("app.country")=="RW") {
            //redirect to select offering
            return false;
        } else {
            return true;
        }
    }
}
if (!function_exists('getPhoneCodeFilter')) {
    function getPhoneCodeFilter($input): string
    {
        $filter = 'members.code="' . $input . '"';
        if (strlen($input) >= 10){
            $filter = 'phone like "%' . $input . '"';
        }
        return $filter;
    }
}
if (!function_exists('saveChurch')) {
    /**
     * Save church for member registration
     * @throws Exception
     */
    function saveChurch(...$args)
    {
        $input = $args[1];
        $member = $args[3];
        $lang = $args[4];
        $cMdl = new EntityModel();
        $churchData = $cMdl->select('id,code')->where('code', $input)->where('entityType', $_SESSION['churchType'])->asObject()->first();
        if ($churchData == null) {
            throw new Exception(lang('app.churchNotFound', [], $lang), 400);
        }
        if ($member == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }
        $mdl = new MemberModel();
        $mdl->save(['entity_id' => $churchData->id, 'id' => $member->id]);
    }
}
if (!function_exists('saveEditChurch')) {
    /**
     * Save church for member registration
     * @throws Exception
     */
    function saveEditChurch(...$args): string
    {
        $input = $args[1];
        $member = $args[3];
        $lang = $args[4];
        $cMdl = new EntityModel();
        $churchData = $cMdl->select('id,code,title')->where('code', $input)->where('entityType', $_SESSION['churchType'])->asObject()->first();
        if ($churchData == null) {
            throw new Exception(lang('app.churchNotFound', [], $lang), 400);
        }
        if ($member == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }
        $mdl = new MemberModel();
        $mdl->save(['entity_id' => $churchData->id, 'id' => $member->id]);
        return lang('app.churchChangeTo', [$churchData->title], $lang);
    }
}
if (!function_exists('saveChurchRegistrar')) {
    /**
     * Save church for member registration
     * @throws Exception
     */
    function saveChurchRegistrar(...$args)
    {
        $mdl = new MemberModel();
        $input = $args[1];
        $phone = $args[2];
        $member = $mdl->select('id')->where('registrarPhone', $phone)->where('phone', null)->orderBy('id', 'DESC')->asObject()->first();
        $lang = $args[4];
        $cMdl = new EntityModel();
        $churchData = $cMdl->select('id,code')->where('code', $input)->where('entityType', $_SESSION['churchType'])->asObject()->first();
        if ($churchData == null) {
            throw new Exception(lang('app.churchNotFound', [], $lang), 400);
        }
        if ($member == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }
        $mdl->save(['entity_id' => $churchData->id, 'id' => $member->id, 'code' => generateMemberCode()]);
    }
}
if (!function_exists('savePaymentChurch')) {
    /**
     * Save church for payment of Visitor or anonymous
     * @throws Exception
     */
    function savePaymentChurch(...$args)
    {
        $sessionId = $args[0];
        $input = $args[1];
        $phone = $args[2];
        $member = $args[3];
        $lang = $args[4];
        $previousInput = $args[5];
        $cMdl = new EntityModel();
        $churchData = $cMdl->select('id,code')->where('code', $input)->where('entityType', $_SESSION['churchType'])->asObject()->first();
        if ($churchData == null) {
            throw new Exception(lang('app.churchNotFound', [], $lang), 400);
        }

        if ($member == null && $previousInput != 4) {
            throw new Exception(lang('app.register_first', [], $lang), 500);
        }
        $memberId = $member == null ? null : $member->id;
        if (payment($phone, $memberId, $churchData->id, $sessionId) === false) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }
    }
}
if (!function_exists('updatePaymentOffering')) {
    /**
     * Update offerings by payment
     * @throws Exception
     */
    function updatePaymentOffering(...$args)
    {
        $sessionId = $args[0];
        $input = $args[1];
        $phone = $args[2];
        $member = $args[3];
        $lang = $args[4];
        $extra = $args[6];
        $data = $args[7];
        $pMdl = new PaymentsModel();
        $paymentData = $pMdl->select('id, churchId')->where('sessionId', $sessionId)->asObject()->first();
        if ($member == null && $paymentData == null) {
            throw new Exception(lang('app.register_first', [], $lang), 500);
        }

        if ($paymentData == null) {
            //create new
            if (empty($member->entity_id)) {
                throw new Exception(lang('app.churchNotFound', [], $lang), 400);
            }
            $churchId = $member->entity_id;
            $extra = json_decode($extra, true);
            if (isset($extra['church'])) {
                //check if there is church code
                $cMdl = new EntityModel();
                $churchData = $cMdl->select('id,code')->where('code', $extra['church'])->where('entityType', $_SESSION['churchType'])->asObject()->first();
                if ($churchData == null) {
                    throw new Exception(lang('app.churchNotFound', [], $lang), 400);
                }
                $churchId = $churchData->id;
            }
            if (($paymentId = payment($phone, $member->id, $churchId, $sessionId)) === false) {
                throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
            }
        } else {
            $paymentId = $paymentData->id;
        }
//        $offMdl = new OfferingsModel();
//        $offerings = $offMdl->orderBy('id', 'asc')->where('status', 1)->get()->getResult();
//        $count = 1;
//        $offeringData = null;
//        foreach ($offerings as $offering) {
//            if ($input == $count) {
//                $offeringData = $offering;
//            }
//            $count++;
//        }
//        $offeringId = $offeringData->id;

        $USSDData = json_decode($data, true);
        if ($USSDData == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 400);
        }
        if (!isset($USSDData[$input])) {
            throw new Exception(lang('app.invalidInput', [], $lang), 400);
        }
        if (!isset($USSDData[$input]) || !filter_var($input, FILTER_VALIDATE_INT)) {
            throw new Exception(lang('app.invalidInput', [], $lang), 400);
        }
        $input = $input-1;
        $offeringId = $USSDData[$input]['id'];

        if ($offeringId == null) {
            throw new Exception(lang('app.invalidInput', [], $lang), 400);
        }
        $pOMdl = new PaymentOfferingsModel();
        try {
            $pOMdl->insert(['payment_id' => $paymentId, 'offeringId' => $offeringId]);
        } catch (Exception $e) {
            if ($e->getCode() == 1062) {
                throw new Exception("Offering already selected\n 0. Back", 400);
            }
            throw $e;
        }

    }
}
if (!function_exists('updatePaymentOfferingNarration')) {
    /**
     * Update offerings by payment for other offering with narration
     * @throws Exception
     */
    function updatePaymentOfferingNarration(...$args)
    {
        $sessionId = $args[0];
        $input = $args[1];
        $phone = $args[2];
        $member = $args[3];
        $lang = $args[4];
        $extra = $args[6];
        $data = $args[7];
        $pMdl = new PaymentsModel();
        $paymentData = $pMdl->select('id, churchId')->where('sessionId', $sessionId)->asObject()->first();
        if ($member == null && $paymentData == null) {
            throw new Exception(lang('app.register_first', [], $lang), 500);
        }
        $extraData = json_decode($extra, true);

        if ($paymentData == null) {
            //create new
            if (empty($member->entity_id)) {
                throw new Exception(lang('app.churchNotFound', [], $lang), 400);
            }
            $churchId = $member->entity_id;
            if (isset($extraData['church'])) {
                //check if there is church code
                $cMdl = new EntityModel();
                $churchData = $cMdl->select('id,code')->where('code', $extraData['church'])->where('entityType', $_SESSION['churchType'])->asObject()->first();
                if ($churchData == null) {
                    throw new Exception(lang('app.churchNotFound', [], $lang), 400);
                }
                $churchId = $churchData->id;
            }
            if (($paymentId = payment($phone, $member->id, $churchId, $sessionId)) === false) {
                throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
            }
        } else {
            $paymentId = $paymentData->id;
        }
//        $offMdl = new OfferingsModel();
//        $offerings = $offMdl->orderBy('id', 'asc')->where('status', 1)->get()->getResult();
//        $count = 1;
//        $offeringData = null;
//        foreach ($offerings as $offering) {
//            if ($input == $count) {
//                $offeringData = $offering;
//            }
//            $count++;
//        }
//        $offeringId = $offeringData->id;

if ($extraData == null) {
    throw new Exception(lang('app.USSDSystemError', [], $lang), 400);
}
if (!isset($extraData['offering'])) {
    throw new Exception(lang('app.invalidInput', [], $lang), 400);
}
$offeringId = $extraData['offering'];

        if ($offeringId == null) {
            throw new Exception(lang('app.invalidInput', [], $lang), 400);
        }
        $pOMdl = new PaymentOfferingsModel();
        try {
            $pOMdl->insert(['payment_id' => $paymentId, 'offeringId' => $offeringId,'narration'=>$input]);
        } catch (Exception $e) {
            if ($e->getCode() == 1062) {
                throw new Exception("Offering already selected\n 0. Back", 400);
            }
            throw $e;
        }

    }
}
if (!function_exists('saveAmount')) {
    /**
     * Update offerings amount by payment
     * @throws Exception
     */
    function saveAmount(...$args)
    {
        $sessionId = $args[0];
        $input = $args[1];
        $member = $args[3];
        $lang = $args[4];
        $pMdl = new PaymentsModel();
        $paymentData = $pMdl->select('id')->where('sessionId', $sessionId)->asObject()->first();
        if ($paymentData == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 400);
        }
        $sMdl = new \App\Models\SettingModel();
        $settings = $sMdl->getSettings(['min_offering_amount']);
        if (!filter_var($input, FILTER_VALIDATE_INT) || $settings['min_offering_amount'] > $input) {
            throw new Exception(lang('app.invalidAmount', [$settings['min_offering_amount']], $lang), 400);
        }
        $pOMdl = new PaymentOfferingsModel();
        $pOMdl->set('amount', $input)->where(['payment_id' => $paymentData->id, 'amount' => null])->update();
    }
}
if (!function_exists('saveMember')) {
    /**
     * Complete member registration
     * @throws Exception
     */
    function saveMember(...$args): string
    {
        $member = $args[3];
        $lang = $args[4];
        $phone = $args[2];
        if ($member == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }
        $mdl = new MemberModel();
        $mdl->save(['status' => 1, 'id' => $member->id]);
        $smsMdl = new SmsSentModel();
        $message = generateRegistrationSMS($phone, $lang);
        $status = 0;
//        log_message('critical', 'Registration sms: ' . $message);
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
            $smsMdl->save(['phone' => $phone, 'type' => 5, 'typeId' => $member->id, 'smsCount'=> countSMS($message)
                ,'message'=>$message, 'memberId' => $member->id, 'status' => $status, 'errorMessage' => $errorMsg]);
        } catch (ReflectionException $e) {
            log_message('critical', 'Registration sms not sent: Member id: ' . $member->id . ', Message: ' . $message);
        }
        return lang('app.successfullyRegistered', [], $lang);
    }
}
if (!function_exists('saveMemberRegistrar')) {
    /**
     * Complete member registration
     * @throws Exception
     */
    function saveMemberRegistrar(...$args): string
    {
        $mdl = new MemberModel();
        $phone = $args[2];
        $member = $mdl->select('id')->where('registrarPhone', $phone)->where('phone', null)->orderBy('id', 'DESC')->asObject()->first();
        $lang = $args[4];
        if ($member == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }
        $mdl->save(['status' => 1, 'id' => $member->id]);
        return lang('app.successfullyRegistered', [], $lang);
    }
}
if (!function_exists('discardMember')) {
    /**
     * Complete member registration
     * @throws Exception
     */
    function discardMember(...$args): string
    {
        $member = $args[3];
        $lang = $args[4];
        if ($member == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }
        return lang('app.anyTimeResume', [], $lang);
    }
}
if (!function_exists('endSession')) {
    /**
     * @throws Exception
     */
    function endSession(...$args): string
    {
        $lang = $args[4];
        return lang('app.endSession', [], $lang);
    }
}
if (!function_exists('showMemberDetails')) {
    /**
     * @throws Exception
     */
    function showMemberDetails(...$args): string
    {
        $phone = $args[2];
        $lang = $args[4];
        $memberMdl = new MemberModel();
        $member = $memberMdl->select('members.id,names,members.code,entity_id,defaultLanguage,members.code as member_code,members.status,members.phone,e.title,e.code,networkOperator')
            ->join('entity e', 'e.id = members.entity_id', 'LEFT')
            ->like('phone', $phone, 'before')->asObject()->first();
        if ($member == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }

        return $member->names . ", " . lang('app.urDetails', [], $lang) . " " . $member->title . "\n" . lang('app.phone', [], $lang) . ": "
            . $member->phone . "\n" . lang('app.code', [], $lang) . ': ' . $member->member_code . "\n" . lang('app.yesNo', [], $lang);
    }
}
if (!function_exists('showMemberDetailsEdit')) {
    /**
     * @throws Exception
     */
    function showMemberDetailsEdit(...$args): string
    {
        $phone = $args[2];
        $lang = $args[4];
        $memberMdl = new MemberModel();
        $member = $memberMdl->select('members.id,names,members.code,entity_id,defaultLanguage,members.code as member_code,members.status,members.phone,e.title,e.code,networkOperator')
            ->join('entity e', 'e.id = members.entity_id', 'LEFT')
            ->like('phone', $phone, 'before')->asObject()->first();
        if ($member == null) {
            throw new Exception(lang('app.memberNotFound', [], $lang), 400);
        }

        return lang('app.names', [], $lang) . $member->names . "\n" . lang('app.church', [], $lang)
            . $member->title . "\n" . lang('app.phone', [], $lang) . ": "
            . $member->phone . "\n" . lang('app.code', [], $lang) . ': ' . $member->member_code . "\n\n"
            . lang('app.editMemberName', [], $lang) . "\n"
            . lang('app.editMemberChurch', [], $lang);
    }
}
if (!function_exists('showMemberDetailsRegistrar')) {
    /**
     * @throws Exception
     */
    function showMemberDetailsRegistrar(...$args): string
    {
        $phone = $args[2];
        $lang = $args[4];
        $memberMdl = new MemberModel();
        $member = $memberMdl->select('members.id,names,members.code,entity_id,defaultLanguage,members.status,members.phone,e.title,members.code as member_code,e.code,networkOperator')
            ->join('entity e', 'e.id = members.entity_id', 'LEFT')
            ->where('registrarPhone', $phone)
            ->where('phone', null)
            ->orderBy('id', 'DESC')
            ->asObject()->first();
        if ($member == null) {
            throw new Exception(lang('app.USSDSystemError', [], $lang), 500);
        }

        return $member->names . ", " . lang('app.urDetails', [], $lang) . " " . $member->title . "\n" . lang('app.phone', [], $lang) . ": "
            . lang('app.notAvailable', [], $lang) . "\n" . lang('app.code', [], $lang) . ": " . $member->member_code . "\n" . lang('app.yesNo', [], $lang);
    }
}
if (!function_exists('showPaymentDetails')) {
    /**
     * @throws Exception
     */
    function showPaymentDetails(...$args): string
    {
        $sessionId = $args[0];
        $lang = $args[4];
        $member = $args[3];
        $pMdl = new PaymentsModel();
        $paymentData = $pMdl->select("po.amount,o.title,o.translation,coalesce(po.narration,'') as narration,e.title")
            ->join('entity e', 'e.id = payments.churchId')
            ->join('payments_offerings po', 'po.payment_id = payments.id')
            ->join('offerings o', 'po.offeringId = o.id')
            ->where('sessionId', $sessionId)->get()->getResult();
        if ($paymentData == null) {
            throw new Exception(lang('app.invalidInput', [], $lang), 500);
        }
        $total = 0;
        $name = $member == null ? lang('app.anonymous', [], $lang) : $member->names;
        $offerings = '';
        foreach ($paymentData as $datum) {
            $total += $datum->amount;
            $translation = json_decode($datum->translation);
            $text = $translation->$lang ?? $translation->en;
            $narration = !empty($datum->narration)?"($datum->narration)":"";
            $offerings .= "\n" . $text.$narration . ": " . $datum->amount;
        }
        return lang('app.names', [], $lang) . " " . $name . "\n"
            . lang('app.church', [], $lang) . " " . $paymentData[0]->title . "\n\n"
            . lang('app.confirmAmount', [], $lang) . " " . $offerings . "\n"
            . lang('app.total', [], $lang) . " " . $total . "\n\n" . lang('app.yesNo', [], $lang);
    }
}
if (!function_exists('showOfferings')) {
    /**
     * @throws Exception
     */
    function showOfferings(...$args): string
    {
        $sessionId = $args[0];
        $lang = $args[4];
        $phone = $args[2];
        $extra = $args[6];
        $offMdl = new OfferingsModel();
        $offerings = $offMdl->select('offerings.id,offerings.title,offerings.translation,coalesce(po.amount,"") as amount
        , e.title as church')->orderBy('id', 'asc')
            ->join('payments p', 'p.sessionId = ' . $sessionId, 'LEFT')
            ->join('entity e', 'p.churchId = e.id', 'LEFT')
            ->join('payments_offerings po', 'po.offeringId = offerings.id AND po.amount is not null AND po.payment_id = p.id', 'LEFT')
            ->where('offerings.status', 1)
            ->groupBy('offerings.id')
            ->get()->getResult();
        $count = 1;
        $msg = '';
        $church = null;
        $mdl = new USSDFlow();
        $mdl->set('data', json_encode($offerings))->where('sessionId', $sessionId)->update();
        foreach ($offerings as $offering) {
            if (empty($church)) {
                $church = $offering->church;
            }
            $translation = json_decode($offering->translation);
            $tr = $translation->$lang ?? $translation->en;
            $amountStr = !empty($offering->amount) ? ' (' . number_format($offering->amount) . ')' : '';
            $msg .= $count . '. ' . $tr . $amountStr . "\n";
            $count++;
        }
        if (empty($msg)) {
            throw new Exception(lang('app.noOfferingsAvailable', [], $lang), 400);
        }
        $memberMdl = new MemberModel();
        $member = $memberMdl->select('members.id,names,members.code,entity_id,defaultLanguage,members.status,members.phone,e.title,e.code,networkOperator')
            ->join('entity e', 'e.id = members.entity_id', 'LEFT')
            ->where('phone like "%' . $phone . '" or members.code="' . $phone . '"')->asObject()->first();
        if ($member != null) {
            $church = $church ?? $member->title;
            $extra = json_decode($extra, true);
            if (isset($extra['church'])) {
                //check if there is church code
                $cMdl = new EntityModel();
                $churchData = $cMdl->select('id,code,title')->where('code', $extra['church'])->where('entityType', $_SESSION['churchType'])->asObject()->first();
                if ($churchData == null) {
                    throw new Exception(lang('app.churchNotFound', [], $lang), 400);
                }
                $church = $churchData->title;
            }
            $memberDetails = lang('app.names', [], $lang) . $member->names . "\n" . lang('app.church', [], $lang) . $church . "\n\n";
        } else {
            $memberDetails = lang('app.names', [], $lang) . lang('app.anonymous', [], $lang) . "\n" . lang('app.church', [], $lang) . $church . "\n\n";
        }
        return $memberDetails . $msg;
    }
}
if (!function_exists('fastHubTzPay')) {
    /**
     * @throws Exception
     */
    function fastHubTzPay(...$args): string
    {
        $sessionId = $args[0];
        $lang = $args[4];
        $pController = new PaymentController();
        return $pController->fastHubTzPay($lang, $sessionId);
    }
}
if (!function_exists('mtnRwPay')) {
    /**
     * @throws Exception
     */
    function mtnRwPay(...$args): string
    {
        $sessionId = $args[0];
        $lang = $args[4];
        $pController = new PaymentController();
        return $pController->mtnRwPay($lang, $sessionId);
    }
}
if (!function_exists('stanbicUgPay')) {
    /**
     * @throws Exception
     */
    function stanbicUgPay(...$args): string
    {
        $sessionId = $args[0];
        $lang = $args[4];
        $pController = new PaymentController();
        return $pController->stanbicUgPay($lang, $sessionId);
    }
}
if(!function_exists('isKeyExists')){
    function isKeyExists($array, $key, $value){
        $index = 0;
        foreach ($array as $rec){
            if ($rec[$key] == $value){
                //already exists
                return $index;
            }
            $index++;
        }
        return false;
    }
}
