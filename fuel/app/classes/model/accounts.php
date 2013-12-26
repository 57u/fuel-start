<?php
/**
 * accounts ORM and reusable function
 * 
 * @author Vee W.
 * @license http://opensource.org/licenses/MIT
 * 
 */

class Model_Accounts extends \Orm\Model 
{


	protected static $_table_name = 'accounts';
	protected static $_primary_key = array('account_id');
	
	// relations
	protected static $_has_many = array(
		'account_level' => array(
			'key_from' => 'account_id',
			'model_to' => 'Model_AccountLevel',
			'key_to' => 'account_id',
			'cascade_save' => true,
			'cascade_delete' => false,
		),
		'account_fields' => array(
			'model_to' => 'Model_AccountFields',
			'key_from' => 'account_id',
			'key_to' => 'account_id',
			'cascade_delete' => true,
		),
	);
	
	
	/**
	 * confirm register
	 * 
	 * @param array $data
	 * @return boolean|string if passed return true. if failed return error text.
	 */
	public static function confirmRegister(array $data = array()) 
	{
		// check username and confirm code.
		$query = self::query()
				->where('account_username', $data['account_username'])
				->where('account_confirm_code', $data['account_confirm_code'])
				->where('account_status', '0')// newly registered user has status 0
				->where('account_last_login', null);// newly registered user never login
		if ($query->count() <= 0) {
			// not found.
			unset($query);
			return \Lang::get('account.account_your_confirm_register_code_is_invalid');
		} else {
			$row = $query->get_one();
			unset($query);
		}
		
		// found user with this confirm code. update account status to 1 to allow login.
		$account = self::find($row->account_id);
		$account->account_confirm_code = null;
		$account->account_status = 1;
		$account->account_status_text = null;
		$account->save();
		
		unset($account, $row);
		
		return true;
	}// confirmRegister
	
	
	/**
	 * hash password
	 * @param string $password
	 * @return string
	 */
	public function hashPassword($password = '') 
	{
		// @todo any hash password api should be here with if condition.
		
		include_once APPPATH . DS . 'vendor' . DS . 'phpass' . DS . 'PasswordHash.php';
		$PasswordHash = new PasswordHash(12, false);
		return $PasswordHash->HashPassword($password);
	}// hashPassword
	
	
	/**
	 * create instance to call non-static method from static method.
	 * @return \Model_Accounts
	 */
	private static function instance() 
	{
		return new Model_Accounts();
	}// instance
	
	
	/**
	 * register new account
	 * 
	 * @param array $data
	 * @param array $data_fields additional fields to store in account_fields table.
	 * @return boolean|string return true when completed and return error text when error occured.
	 */
	public static function registerAccount($data = array(), $data_fields = array()) 
	{
		// check required data.
		if (empty($data) || !is_array($data)) {return false;}
		
		// get configurations db
		$cfg = \Model_Config::getvalues(array('member_verification', 'member_disallow_username'));
		
		// verify disallow username.
		if (isset($cfg['member_disallow_username']['value'])) {
			$cfg['member_disallow_username']['value'] = str_replace(', ', ',', $cfg['member_disallow_username']['value']);
			$disallow_usernames = explode(',', $cfg['member_disallow_username']['value']);
			foreach ($disallow_usernames as $disallow_username) {
				if ($data['account_username'] == trim($disallow_username)) {
					unset($cfg, $disallow_username, $disallow_usernames);
					return \Lang::get('account.account_username_disallowed');
				}
			}
		}
		
		// check duplicate username.
		$query = self::query()->select('account_username')->where('account_username', $data['account_username']);
		if ($query->count() > 0) {
			unset($query);
			return \Lang::get('account.account_username_already_exists');
		}
		unset($query);
		
		// check duplicate email.
		$query = self::query()->select('account_email')->where('account_email', $data['account_email']);
		if ($query->count() > 0) {
			unset($query);
			return \Lang::get('account.account_email_already_exists');
		}
		unset($query);
		
		if ($cfg['member_verification']['value'] != '0') {
			// admin config need to verify.
			// generate confirm code
			$data['account_confirm_code'] = \Str::random('alnum', 6);
		}
		
		// send register email
		$send_result = self::sendRegisterEmail($data);
		if ($send_result !== true) {
			return $send_result;
		}
		unset($send_result);
		
		$data['account_password'] = self::instance()->hashPassword($data['account_password']);
		$data['account_create'] = time();
		$data['account_create_gmt'] = \Extension\Date::localToGmt();
		if ($cfg['member_verification']['value'] == '0') {
			// admin config to no need to verify.
			$data['account_status'] = '1';
		} else {
			$data['account_status'] = '0';
			if ($cfg['member_verification']['value'] == '2') {
				$data['account_status_text'] = \Lang::get('account.account_waiting_for_admin_verification');
			} else {
				$data['account_status_text'] = \Lang::get('account.account_please_confirm_registration_from_your_email');
			}
		}
		
		// add account to db. ----------------------------------------
		//list($account_id) = \DB::insert('accounts')->set($data); // query builder style.
		$account = self::forge($data);
		
		// add level to user by use single site table structure.
		$account->account_level[0] = new Model_AccountLevel();
		$account->account_level[0]->level_group_id = 3;
		
		$account->save();
		$account_id = $account->account_id;
		unset($account);
		// end add account to db -------------------------------------
		
		// add level to user.
		// @todo for multi site with table site id prefix, you need to modify and loop those [site id]_account_level to add level to user.
		/*
		//foreach ($list_site['items'] as $site) {
		$account_level = new \Model_AccountLevel;
		$account_level->level_group_id = 3;
		$account_level->account_id = $account_id;
		$account_level->save();
		unset($account_level);
		// }
		 */
		
		// add account fields if there is any value.
		// to add account fields data structure shoud be like this...
		// array(array('field_name' => 'website', 'field_value' => 'http://domain.tld'), array('field_name' => 'fb', 'field_value' => 'http://fb.com/myprofile'));
		// or
		// $af[0]['field_name'] = 'website';
		// $af[0]['field_value'] = 'http://domain.tld';
		// $sf[1]['field_name'] = 'fb';
		// $sf[1]['field_value'] = 'http://fb.com/myprofile';
		if (!empty($data_fields) && is_array($data_fields)) {
			foreach ($data_fields as $field) {
				$account_fields = self::forge($field);
				$account_fields->account_id = $account_id;
				$account_fields->save();
			}
			unset($account_fields, $field);
		}
		
		// @todo register account api should be here.
		
		return true;
	}// registerAccount.
	
	
	/**
	 * send register email
	 * @param array $data
	 * @return boolean|string return true when send register email was done and return error text when error occured.
	 */
	public static function sendRegisterEmail($data = array(), $options = array()) 
	{
		if (!isset($data['account_username']) || !isset($data['account_email']) || !isset($data['account_confirm_code'])) {return false;}
		
		$cfg = \Model_Config::getvalues(array('member_verification', 'mail_sender_email', 'member_register_notify_admin', 'member_admin_verify_emails'));
		
		// email content
		$member_verification = $cfg['member_verification']['value'];
		if ($member_verification == '0') {
			// not verify.
			$not_verify_register = true;
		} elseif ($member_verification == '1') {
			// verify by email. (user verify)
			$email_content = \Extension\EmailTemplate::readTemplate('register_user_verify_account.html');
		} elseif ($member_verification == '2') {
			// verify by admin. (admin allow or not)
			$email_content = \Extension\EmailTemplate::readTemplate('register_admin_verify_account.html');
		}
		
		// modify email content for ready to send.
		if (isset($email_content) && $email_content != null) {
			$email_content = str_replace("%username%", \Security::htmlentities($data['account_username']), $email_content);
			$email_content = str_replace('%register_confirm_link%', \Uri::create('account/confirm-register/'.urlencode($data['account_username']).'/'.urlencode($data['account_confirm_code'])), $email_content);
		} elseif (isset($email_content) && $email_content == null) {
			return \Lang::get('account.account_unable_to_load_email_template');
		}
		
		// if need to send verify register
		if (!isset($not_verify_register) || (isset($not_verify_register) && $not_verify_register == false)) {
			// send email to notify user, admin to verify registration
			\Package::load('email');
			$config = \Extension\Email::getConfig();
			$email = \Email::forge($config);
			$email->from($cfg['mail_sender_email']['value']);
			$email->to($data['account_email']);
			if ($member_verification == '1') {
				$email->subject(\Lang::get('account.account_please_confirm_your_account'));
			} elseif ($member_verification == '2') {
				$email->subject(\Lang::get('account.account_please_verify_user_registration'));
			}
			$email->html_body($email_content);
			$email->alt_body(str_replace("\t", '', strip_tags($email_content)));
			if ($email->send() == false) {
				// email could not sent.
				unset($cfg, $config, $email, $email_content, $member_verification, $not_verify_register);
				return \Lang::get('account.account_email_could_not_send');
			}
			unset($email, $email_content, $not_verify_register);
		}
		
		// if member verification need admin to verify OR register needs to notify admin.
		if (($member_verification == '2' || $cfg['member_register_notify_admin']['value'] == '1') && (!isset($options['not_notify_admin']) || (isset($options['not_notify_admin']) && $options['not_notify_admin'] == false))) {
			// email content
			$email_content = \Extension\EmailTemplate::readTemplate('register_notify_admin.html');
			$email_content = str_replace("%username%", \Security::htmlentities($data['account_username']), $email_content);
			
			\Package::load('email');
			$config = \Extension\Email::getConfig();
			$email = \Email::forge($config);
			$email->from($cfg['mail_sender_email']['value']);
			$email->to(\Extension\Email::setEmails($cfg['member_admin_verify_emails']['value']));
			$email->subject(\Lang::get('account.account_notify_admin_new_register_account', array('username' => $data['account_username'])));
			$email->html_body($email_content);
			$email->alt_body(str_replace("\t", '', strip_tags($email_content)));
			if ($email->send() == false) {
				// email could not sent.
				unset($cfg, $config, $email, $email_content, $member_verification, $not_verify_register);
				return \Lang::get('account.account_email_could_not_send');
			}
		}
		
		unset($cfg, $config, $member_verification, $not_verify_register);
		
		return true;
	}// sendRegisterEmail


}

