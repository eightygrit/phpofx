<?php

namespace Seventymph\OFX;

/**
 * Interact with OFX servers.
 *
 **/
class OFX
{
    const $REQUEST = <<<'OFX'
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
            <LANGUAGE>ENG
            <FI>
            <ORG>${ORG}
            <FID>${FID}
            </FI>
            <APPID>QWIN
            <APPVER>0900
        </SONRQ>
    </SIGNONMSGSRQV1>
    <BANKMSGSRQV1>
        <STMTTRNRQ>
            <TRNUID>23382938
            <STMTRQ>
                <BANKACCTFROM>
                    <BANKID>${BANK_ID}
                    <ACCTID>${ACCT_ID}
                    <ACCTTYPE>CHECKING
                </BANKACCTFROM>
                <INCTRAN>
                    <INCLUDE>Y
                </INCTRAN>
            </STMTRQ>
        </STMTTRNRQ>
    </BANKMSGSRQV1>
</OFX>
OFX;

    /**
     * Constructor.
     *
     * @param array $config Valid keys:
     * -user_id string
     * -password string
     * -org string
     * -fid string
     * -bank_id string
     * -acct_id string
     * @return void
     **/
    public function __construct($config) {
        $user_id = null;
        $password = null;
        $org = null;
        $fid = null;
        $bank_id = null;
        $acct_id = null;
        extract($config);

        if (empty($user_id) || empty($password) || empty($org) || empty($fid)
            || empty($bank_id) || empty($acct_id))
        {
            throw new Exception("Did not supply all parameters.");
        }

        $this->_user_id = $user_id;
        $this->_password = $password;
        $this->_org = $org;
        $this->_fid = $fid;
        $this->_bank_id = $bank_id;
        $this->_acct_id = $acct_id;
    }

    private $_user_id;
    private $_org;
    private $_fid;
    private $_bank_id;
    private $_acct_id;
} // END class OFX
