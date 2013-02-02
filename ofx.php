<?php

/**
 * Interact with OFX servers.
 *
 **/
class OFX
{
    const HANDSHAKE = <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252
COMPRESSION:NONE
OLDFILEUID:NONE
NEWFILEUID:NONE

<OFX>
    <SIGNONMSGSRQV1>
        <SONRQ>
            <DTCLIENT>${TIMESTAMP}
            <USERID>${USER_ID}
            <USERPASS>${PASSWORD}
            <GENUSERKEY>N
            <LANGUAGE>ENG
            <FI>
                <ORG>${ORG}
                <FID>${FID}
            </FI>
            <APPID>QWIN
            <APPVER>0900
        </SONRQ>
    </SIGNONMSGSRQV1>
${REQUEST_XML}
</OFX>
OFX;

    /**
     * Constructor.
     *
     * @param array $config Valid keys (all required):
     * -uri string
     * -user_id string
     * -password string
     * -org string
     * -fid string
     * -bank_id string
     * -acct_id string
     * @return void
     **/
    public function __construct($config) {
        $uri = null;
        $user_id = null;
        $password = null;
        $org = null;
        $fid = null;
        $bank_id = null;
        extract($config);

        if (empty($uri) ||empty($user_id) || empty($password) || empty($org)
            || empty($fid) || empty($bank_id))
        {
            throw new Exception("Did not supply all parameters.");
        }

        $this->_uri = $uri;
        $this->_user_id = $user_id;
        $this->_password = $password;
        $this->_org = $org;
        $this->_fid = $fid;
        $this->_bank_id = $bank_id;

        // Relates to LF server error.
        $this->is_windows_server = null;


    }

    private function get_timestamp(){

        date_default_timezone_set("America/Chicago");

        $tz = strftime("%z", time());
        $tz = intval($tz) / 100;  // Have to hack off the "00" at the end.

        if ($tz >= 0) {
            $tz = "+$tz";
        }
        $now = strftime("%Y%m%d%H%M%S.000[$tz:%Z]", time());

        return $now;
    }

    static function convert_to_unix_lf(&$string){
        // Windows uses CRLF line feed whereas
        // Unix uses LF line feed.
        $string = str_replace("\r\n", "\n", $string);

    }
    static function convert_to_windows_lf(&$string){
        // Windows uses CRLF line feed whereas
        // Unix uses LF line feed.
        $string = str_replace("\n", "\r\n", $string);

    }

    private function ofx_to_xml($ofx)
    {
        // close the tags, so it resembles XML
        $ofx = preg_replace('/(<([^<\/]+)>)(?!.*?<\/\2>)([^<]+)/', '\1\3</\2>', $ofx);

        $xml = new SimpleXMLElement($ofx);

        return $xml;
    }


    /**
     * Check for Errors in the Server Response
     *
     * Place test for any know errors here.
     * Currently, only one error is known about:
     * When dealing with a Windows server,
     * the linefeeds are not compatible and
     * the request must be fixed and reprocessed.
     *
     * @return bool
     */
    private function check_response_errors($response_header)
    {
        if(preg_match("/(Failed to parse request)(.+)(unable to find end delimiters)/ui", $response_header))
            {
                // We have an error. Deja Vu?
                // This checks if we have already been through this loop.
                // Last time we were here, we set is_windows_server to true.
                // We should't go through it more than once.
                if($this->is_windows_server){
                    // Looks like we have already been through the loop and we are still getting a parse error? Time to die.
                    error_log("You are getting a 'fail' response from the server. You've already tried to convert line feed from unix format to Windows format, just in case that was the cause of the problem. But that didn't resolve the issue. Here's the response from the server:\n".print_r($response_header, 1));

                    die;
                }
                // If you are on this line, you have failed only once.
                // Based on the server error - you are likely dealing with a Windows server.
                // Set trigger so we don't get stuck in a loop.

                $this->is_windows_server = true;
                return true;

            } else {

                // If there are other known errors, check for them here.


                // For now, the response doesn't match any known errors.
                return false;

            }

    }



    /**
     * Do Server Request
     *
     * @return object XML
     **/
    private function do_request($request){
        $request_lf_type = 'unix';

        retry_request:

        // Check Line Feed Type
        if ($request_lf_type == 'unix' && $this->is_windows_server == true) {
            $this->convert_to_windows_lf($request);
            $request_lf_format = 'windows';
        }

        // Perform the HTTP request.
        $curl = curl_init($this->_uri);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-ofx",
            "Accept: */*, application/x-ofx",
        ));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
        $response = curl_exec($curl);
        curl_close($curl);

        // Begin parsing
        $response = explode('<OFX>', $response);
        $response_header = $response[0];
        $response_body = '<OFX>' . $response[1];
        // Check for errors
        $is_error = $this->check_response_errors($response_header);

        if ($is_error)
        {
            goto retry_request;

        } else {
            // There was no error, but let's check to see if the response has Windows line feeds and if so, convert them back to Unix style.
            if ($this->is_windows_server === true)
                // Format the request
                $this->convert_to_unix_lf($response_body);

        }

        // echo "\n\n\n\n\n\n\n\n\n\n";
        // var_dump($request);var_dump($response);


        return $response_body;

    }

    /**
     * Fetch Accounts.
     *
     * @return array Accounts.
     **/
    public function fetch_accounts() {

$request_accounts = <<<RA
    <SIGNUPMSGSRQV1>
        <ACCTINFOTRNRQ>
            <TRNUID>
            <ACCTINFORQ>
                <DTACCTUP>19900101
            </ACCTINFORQ>
        </ACCTINFOTRNRQ>
    </SIGNUPMSGSRQV1>
RA;
        $request = OFX::HANDSHAKE;

        $now = $this->get_timestamp();

        $request = str_replace('${TIMESTAMP}', $now, $request);
        $request = str_replace('${USER_ID}', $this->_user_id, $request);
        $request = str_replace('${PASSWORD}', $this->_password, $request);
        $request = str_replace('${ORG}', $this->_org, $request);
        $request = str_replace('${FID}', $this->_fid, $request);
        $request = str_replace('${BANK_ID}', $this->_bank_id, $request);
        $request = str_replace('${REQUEST_XML}', $request_accounts, $request);
        // Generate a unique ID to identify this server request
        $request = str_replace('<TRNUID>', '<TRNUID>'.md5(time().$this->_uri.$this->_user_id), $request);

        // Returns an XML Object
        $results = $this->do_request($request);

        $results = $this->ofx_to_xml($results);

        // Get each Bank account
        foreach($results->xpath('/OFX/SIGNUPMSGSRSV1/ACCTINFOTRNRS/ACCTINFORS/ACCTINFO/BANKACCTINFO/BANKACCTFROM') as $acct) {
            $accounts[] = array(
                (string)$acct->ACCTID,
                'BANK',
                (string)$acct->ACCTTYPE,
                (string)$acct->BANKID
            );
        }

        // Get each Credit Card account
        foreach($results->xpath('/OFX/SIGNUPMSGSRSV1/ACCTINFOTRNRS/ACCTINFORS/ACCTINFO/CCACCTINFO/CCACCTFROM') as $acct) {
            $accounts[] = array(
                (string)$acct->ACCTID,
                'CC'
            );
        }

        return $accounts;

    }

    /**
     * Fetch transations.
     *
     * @return array Transactions.
     **/
    public function fetch_transactions($account=null) {

        // If no specific account was provided, get trasactions for all accounts.
        if ($account === null) {
            $accounts = $this->fetch_accounts();

        // If an array with a single account's details was passed, only those transactions.
        } elseif(is_array($account) && !empty($account)) {
            $accounts[] = $account;

        // If it's an integer, assume its an account id in the db and look it up.
        } elseif(is_int($account)){
            // get details from DB.
            // $accounts = results
        }




        foreach ($accounts as $acct) {

            // Get the first part of the OFX request
            $request = OFX::HANDSHAKE;

            $now = $this->get_timestamp();

            // Replace variables with data for this request.
            $request = str_replace('${TIMESTAMP}', $now, $request);
            $request = str_replace('${USER_ID}', $this->_user_id, $request);
            $request = str_replace('${PASSWORD}', $this->_password, $request);
            $request = str_replace('${ORG}', $this->_org, $request);
            $request = str_replace('${FID}', $this->_fid, $request);
            $request = str_replace('${BANK_ID}', $this->_bank_id, $request);

            if($acct[1] == "BANK")
            {
                list($acct_id, $type, $subtype, $bank_id) = $acct;
$request_txns = <<<BANK
    <BANKMSGSRQV1>
        <STMTTRNRQ>
           <TRNUID>
           <STMTRQ>
               <BANKACCTFROM>
                   <BANKID>${bank_id}
                   <ACCTID>${acct_id}
                   <ACCTTYPE>${subtype}
               </BANKACCTFROM>
               <INCTRAN>
                   <DTSTART>20110301
                   <INCLUDE>Y
               </INCTRAN>
           </STMTRQ>
        </STMTTRNRQ>
    </BANKMSGSRQV1>
BANK;

                $txn_xquery = 'BANKMSGSRSV1/STMTTRNRS/STMTRS/BANKTRANLIST/STMTTRN';

            } elseif ($acct[1] == "CC") {
                list($acct_id, $type) = $acct;

$request_txns = <<<CC
    <CREDITCARDMSGSRQV1>
        <CCSTMTTRNRQ>
            <TRNUID>
            <CCSTMTRQ>
                <CCACCTFROM>
                    <ACCTID>${acct_id}
                </CCACCTFROM>
                <INCTRAN>
                    <DTSTART>20130101
                    <INCLUDE>Y
                </INCTRAN>
            </CCSTMTRQ>
        </CCSTMTTRNRQ>
    </CREDITCARDMSGSRQV1>
CC;

            $txn_xquery = 'CREDITCARDMSGSRSV1/CCSTMTTRNRS/CCSTMTRS/BANKTRANLIST/STMTTRN';
            }



            $request = str_replace('${REQUEST_XML}', $request_txns, $request);
            // Generate a unique ID to identify this server request
            $request = str_replace('<TRNUID>', '<TRNUID>'.md5(time().$this->_uri.$this->_user_id), $request);

            // Returns a string with the response body, then converted to an xml object.
            $results = $this->ofx_to_xml($this->do_request($request));
            // print_r($results);
            // exit;
            // Get the balance. Need to store somehow.
            $xpath['balance'] = $results->xpath('/OFX/*/*/*/LEDGERBAL/BALAMT');
            $balance = (double)$xpath['balance'][0];

            $xpath['available_balance'] = $results->xpath('/OFX/*/*/*/AVAILBAL/BALAMT');

            $available_balance = (empty($xpath['available_balance']) ? $balance : (double)$xpath['available_balance'][0]);


            // BANK gives an array of transactions in the following format:
            //
            // [0] => SimpleXMLElement Object
            //     (
            //         [TRNTYPE] => DEBIT
            //         [DTPOSTED] => 20130131120000
            //         [TRNAMT] => -17.03
            //         [FITID] => 201301310000002
            //         [NAME] => AMAZON           INTERNET   ****
            //     )

            // [1] => SimpleXMLElement Object
            //     (
            //         [TRNTYPE] => XFER
            //         [DTPOSTED] => 20130131120000
            //         [TRNAMT] => -300.0
            //         [FITID] => 201301310000001
            //         [NAME] => USAA FUNDS TRANSFER DB
            //     )
            //
            // ============================================================

            // ============================================================
            // CC gives an array of transactions in the following format:
            //
            // [STMTTRN] => Array
            // (
            //     [0] => SimpleXMLElement Object
            //         (
            //             [TRNTYPE] => DEBIT
            //             [DTPOSTED] => 20130129120000
            //             [TRNAMT] => -32.15
            //             [FITID] => 01/29/13($32.15)25536060X30VXL30V
            //             [SIC] => 7395
            //             [NAME] => SNAPFISH                 PALO AL
            //         )

            //     [1] => SimpleXMLElement Object
            //         (
            //             [TRNTYPE] => CREDIT
            //             [DTPOSTED] => 20130126120000
            //             [TRNAMT] => 600.0
            //             [FITID] => 01/26/13$600.0085458840V2SAPHGTZ
            //             [SIC] => 6010
            //             [NAME] => USAA.COM PMT - THANK YOU SAN ANT
            //         )

            $xpath['txns'] = $results->xpath($txn_xquery);

            $txns = array();
            $i = 0;
            while ($i < count($xpath['txns'])) {

                $txns[$i] = array(
                    'type' => strval($xpath['txns'][$i]->TRNTYPE),
                    'post_date' => strval($xpath['txns'][$i]->DTPOSTED),
                    'amt' => strval($xpath['txns'][$i]->TRNAMT),
                    'id' => strval($xpath['txns'][$i]->FITID),
                    'sic' => ($acct[1] == "CC" ? strval($xpath['txns'][$i]->SIC) : null),
                    'desc' => strval($xpath['txns'][$i]->NAME)
                 );
                $i++;

            }


            //echo "\n Transactions for ". $acct_id. ":\n". print_r($txns,1);

        } // foreach

        // Return.
        return $txns;
    }

    private $_uri = null;
    private $_user_id = null;
    private $_org = null;
    private $_fid = null;
    private $_bank_id = null;
} // END class OFX
