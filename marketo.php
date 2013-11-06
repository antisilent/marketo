<?php
/** marketo.php
 *
 *  A simple Marketo SOAP client. Forked from Flickerbox's very capable original.
 * 
 * @author      Ben Ubois
 * @copyright   2013, Ben Ubois
 * @package     Ozone_PHP_Tools
 * @version     0.2.2
 * @license     http://opensource.org/licenses/MIT
 * 
 * @link        http://www.ozoneonline.com
 * 
 * @see         http://github.com/flickerbox/marketo
 * @see         http://flickerbox.com
 */

class Marketo {
    /**
     * Marketo user ID.
     *
     * @author Ben Ubois
     *
     * @access protected
     * 
     * @var    string
     *
     * @example 'marketousername_00123456789ABC98DEF765'
     */
    protected $user_id;

    /**
     * Marketo encryption key.
     *
     * @author Ben Ubois
     *
     * @access protected
     * 
     * @var    string
     *
     * @example '0011AA22BBCC33DD44EE'
     */
    protected $encryption_key;

    /**
     * Marketo SOAP host. NOT the SOAP end point.
     *
     * @author Ben Ubois
     *
     * @access protected
     * 
     * @var    string
     *
     * @example '123-ABC-456.mktoapi.com'
     */
    protected $soap_host;

    /**
     * Builds the API client. Optional variables may be statically declared within
     * this function for inclusion in a larger project.
     *
     * @author Ben Ubois
     *
     * @access public
     * 
     * @param  $user_id
     * @param  $encryption_key
     * @param  $soap_host
     *
     * @example $marketo = new Marketo();
     * @example $marketo = new Marketo('marketousername_00123456789ABC98DEF765','0011AA22BBCC33DD44EE','123-ABC-456.mktoapi.com');
     */
    public function __construct($user_id = NULL, $encryption_key = NULL, $soap_host = NULL) {

        $this->user_id = $user_id;
        $this->encryption_key = $encryption_key;
        $this->soap_host = $soap_host;

        $soap_end_point = "https://{$this->soap_host}/soap/mktows/2_2";

        $options = array(
          "connection_timeout" => 20,
          "location" => $soap_end_point
        );

        $wsdl_url = $soap_end_point . '?WSDL';

        $this->soap_client = new soapClient($wsdl_url, $options);
    }

    /**
     * Get a lead record
     *
     * @author Ben Ubois
     *
     * @access public
     * 
     * @param  $type - The type of ID you would like to look up the lead by. This can be one of the following: 'idnum' - The Marketo lead ID cookie (The entire _mkto_trk cookie), 'email' - The email address of the lead, 'sdfccontantid' - The Salesforce Contact ID, 'sfdcleadid' - The Salesforce Lead ID
     * @param $value - The value for the key.
     * 
     * @example $marketo->get_lead_by('email', 'user@server.com');
     * 
     * @return boolean : Marketo Lead Object if TRUE, FALSE if no lead present.
     */
    public function get_lead_by($type, $value) {
        $lead = new stdClass;
        $lead->leadKey = new stdClass;
        $lead->leadKey->keyType = strtoupper($type);
        $lead->leadKey->keyValue = $value;

        try {
            $result = $this->request('getLead', $lead);
            $leads = $this->format_leads($result);
        } catch (Exception $e) {
            if (isset($e->detail->serviceException->code) && $e->detail->serviceException->code == '20103') {
                // No leads were found
                $leads = FALSE;
            } else {
                throw new Exception($e, 1);
            }
        }

        return $leads;
    }

    /**
     * Create or update lead information. When no $lead_key or $cookie is given, a new lead will be created. When a $lead_key or $cookie is specified, Marketo will attempt to identify the lead and update it.
     *
     * @author  Ben Ubois
     * 
     * @access  public
     * 
     * @param   $lead - Associative array of lead attributes 
     * @param   $lead_key - Optional, The key being used to identify the lead, this can be either an email or Marketo ID
     * @param   $cookie - Optional, The entire _mkto_trk cookie the lead will be associated with
     * 
     * @example $marketo->sync_lead(array('Email' => 'ben@benubois.com'));
     * @example $marketo->sync_lead(array('Unsubscribed' => FALSE), 'ben@benubois.com', $_COOKIE['_mkto_trk']);
     * 
     * @return object : Marketo Lead Object.
     */
    public function sync_lead($lead, $lead_key = NULL, $cookie = NULL) {
        $params = new stdClass;
        $params->marketoCookie = $cookie;
        $params->returnLead = TRUE;
        $params->leadRecord = $this->lead_record($lead, $lead_key);

        $result = $this->request('syncLead', $params);

        $result = $result->result;
        $result->leadRecord->attributes = $this->flatten_attributes($result->leadRecord->leadAttributeList->attribute);
        unset($result->leadRecord->leadAttributeList);

        return $result;
    }

    /**
     * Get available campaign objects. You would usually use this to figure out what campaigns are available when calling add_to_campaign.
     *
     * @author  Ben Ubois
     *
     * @access  public
     * 
     * @param   $campaign - Optional, the name or Marketo ID of the campaign to get
     * 
     * @return object : Available Marketo Campaigns object.
     */
    public function get_campaigns($campaign = NULL) {
        $params = new stdClass;
        $params->source = 'MKTOWS';

        if (!$campaign) {
            $params->name = '';
        } else {
            if (is_numeric($campaign)) {
                $params->campaignId = $campaign;
            } else {
                $params->name = $campaign;
            }
        }

        return $this->request('getCampaignsForSource', $params);
    }

    /**
     * Schedule an existing campaign.
     *
     * @author  Nick Silva
     *
     * @access  public
     * 
     * @param   $time - the time to run the campaign
     * @param   $campaign - the name of the campaign to schedule
     * @param   $program - the name of the containing program
     * @param   $tokens (optional) - array of My Tokens to be used in campaign
     * 
     * @return object : Scheduled Marketo Campaigns object.
     */
    public function schedule_campaign($time, $campaign, $program, $tokens = NULL) {
        $params = new stdClass;

        $params->source = 'MKTOWS';
        $params->campaignRunAt = $time;
        $params->campaignName = $campaign;
        $params->programName = $program;

        if ($tokens) {
            $params->programTokenList = $tokens;
        }

        return $this->request('scheduleCampaign', $params);
    }

    /**
     * Add leads to a campaign.
     *
     * @author  Ben Ubois
     *
     * @access  public
     * 
     * @param   $campaign_key - Either the campaign id or the campaign name. You can get these from get_campaigns().
     * @param   $leads - An associative array with a key of lead id type the lead id value. This can also be an array of associative arrays. The available id types are: 'idnum' - The Marketo lead ID cookie (The entire _mkto_trk cookie), 'email' - The email address of the lead, 'sdfccontantid' - The Salesforce Contact ID, 'sfdcleadid' - The Salesforce Lead ID
     * 
     * @example $marketo->add_to_campaign(321, array('idnum' => '123456'));
     * @example $leads = array(array('idnum' => '123456'),array('sfdcleadid' => '001d000000FXkBt')); $marketo->add_to_campaign(321, $leads);
     * 
     * @return boolean
     */
    public function add_to_campaign($campaign_key, $leads) {
        $lead_keys = array();
        foreach ($leads as $type => $value) {
            if (is_array($value)) {
                // Just getting the type and value into the right place
                foreach ($value as $type => $value) {
                    
                }
            }

            $lead_key = new stdClass;
            $lead_key->keyType = strtoupper($type);
            $lead_key->keyValue = $value;

            array_push($lead_keys, $lead_key);
        }

        $params = new stdClass;
        $params->leadList = $lead_keys;
        $params->source = 'MKTOWS';

        if (is_numeric($campaign_key)) {
            $params->campaignId = $campaign_key;
        } else {
            $params->campaignName = $campaign_key;
        }

        return $this->request('requestCampaign', $params);
    }

    /**
     * Build a lead object for syncing.
     *
     * @author  Ben Ubois
     *
     * @access  protected
     * 
     * @param   $lead - Associative array of lead attributes
     * @param   $lead_key - Optional, The key being used to identify the lead, this can be either an email or Marketo ID
     * 
     * @return object : Returns an object containing the prepared lead
     */
    protected function lead_record($lead_attributes, $lead_key = NULL) {
        $record = new stdClass;

        // Identify the lead if it is known
        if ($lead_key) {
            if (is_numeric($lead_key)) {
                $record->Id = $lead_key;
            } else {
                $record->Email = $lead_key;
            }
        }

        $record->leadAttributeList = new stdClass;
        $record->leadAttributeList->attribute = array();

        foreach ($lead_attributes as $attribute => $value) {
            $type = NULL;

            // Booleans have to be '1' or '0'
            if (is_bool($value)) {
                $value = strval(intval($value));
                $type = 'boolean';
            }

            $lead_attribute = new stdClass;
            $lead_attribute->attrName = $attribute;
            $lead_attribute->attrValue = $value;
            $lead_attribute->attrType = $type;

            array_push($record->leadAttributeList->attribute, $lead_attribute);
        }

        return $record;
    }

    /**
     * Format Marketo lead object into something easier to work with
     *
     * @author  Ben Ubois
     *
     * @access  protected
     * 
     * @param   $marketo_result - The result of a get_lead call
     * 
     * @return array : Returns an array of formatted lead objects
     */
    protected function format_leads($marketo_result) {
        $leads = array();

        // One record comes back as an object but two comes as an array of objects, just 
        // make them both arrays of objects
        if (is_object($marketo_result->result->leadRecordList->leadRecord)) {
            $marketo_result->result->leadRecordList->leadRecord = array($marketo_result->result->leadRecordList->leadRecord);
        }

        foreach ($marketo_result->result->leadRecordList->leadRecord as $lead) {
            $lead->attributes = $this->flatten_attributes($lead->leadAttributeList->attribute);
            unset($lead->leadAttributeList);

            array_push($leads, $lead);
        }

        return $leads;
    }

    /**
     * Helper for format_leads. Formats attribute objects to a simple associative array.
     *
     * @author  Ben Ubois
     *
     * @access  protected
     * 
     * @param   $attributes - An array of attribute objects from a get_lead call
     * 
     * @return array : Returns a flattened array of attributes
     */
    protected function flatten_attributes($attributes) {
        $php_types = array('integer', 'string', 'boolean', 'float');
        $attributes_array = array();
        foreach ($attributes as $attribute) {
            if (is_object($attribute)) {
                if (in_array($attribute->attrType, $php_types)) {
                    // Cast marketo type to supported php types
                    settype($attribute->attrValue, $attribute->attrType);
                }
                $attributes_array[$attribute->attrName] = $attribute->attrValue;
            }
        }

        return $attributes_array;
    }

    /**
     * Creates a SOAP authentication header to be used in the SOAP request.
     *
     * @author  Ben Ubois
     *
     * @access  protected
     * 
     * @param   $attributes - An array of attribute objects from a get_lead call
     * 
     * @return object : SoapHeader
     */
    protected function authentication_header() {
        $timestamp = date("c");
        $encrypt_string = $timestamp . $this->user_id;
        $signature = hash_hmac('sha1', $encrypt_string, $this->encryption_key);

        $data = new stdClass;
        $data->mktowsUserId = $this->user_id;
        $data->requestSignature = $signature;
        $data->requestTimestamp = $timestamp;

        $header = new SoapHeader('http://www.marketo.com/mktows/', 'AuthenticationHeader', $data);

        return $header;
    }

    /**
     * Make a SOAP request to the Marketo API.
     *
     * @author  Ben Ubois
     *
     * @access  protected
     * 
     * @param   $operation - The name of the soap method being called
     * @param   $params - The object to send with the request
     * 
     * @return object : SOAP request result
     */
    protected function request($operation, $params) {
        return $this->soap_client->__soapCall($operation, array($params), array(), $this->authentication_header());
    }

}

/* End of file marketo.php */
/* Location: marketo.php */