<?php

require_once(EXTENSIONS . '/yubikey_otp/lib/Auth_Yubico-2.5/Yubico.php');
require_once('PEAR.php');

class extension_yubikey_otp extends Extension {

	public function getSubscribedDelegates() {

		return array(

			array(
				'page' => '/backend/',
				'delegate' => 'AdminPagePreGenerate',
				'callback' => 'insertYubikeyOTPField'
			),
			array(
				'page' => '/login/',
				'delegate' => 'AuthorLoginSuccess',
				'callback' => 'validateOTP'
			),
			array(
				'page' => '/system/preferences/',
				'delegate' => 'CustomActions',
				'callback' => 'savePreferences'
			),
			array(
				'page' => '/system/preferences/',
				'delegate' => 'AddCustomPreferenceFieldsets',
				'callback' => 'appendPreferences'
			),
			array(
				'page' => '/system/authors/',
				'delegate' => 'AddElementstoAuthorForm',
				'callback' => 'appendAuthorFields'
			),
			array(
				'page' => '/system/authors/',
				'delegate' => 'AuthorPostCreate',
				'callback' => 'saveAuthorYubikeyId'
			),
			array(
				'page' => '/system/authors/',
				'delegate' => 'AuthorPostEdit',
				'callback' => 'saveAuthorYubikeyId'
			)
		);
	}

	public function install() {
		
		try {
		
			return Symphony::Database()->query('CREATE TABLE `tbl_yubikey_2fa` (
				`id` int(11) unsigned NOT NULL auto_increment,
				`author_id` int(11) unsigned NOT NULL,
				`yubikey_id` varchar(12),
				PRIMARY KEY (`id`),
				UNIQUE KEY (`author_id`))');

		} catch (Exception $ex) {
			
			return false;
		}
	}

	public function uninstall() {
		
		try {
			Symphony::Database()->query('DROP TABLE `tbl_yubikey_2fa`');
			Symphony::Configuration()->remove('yubikey_2fa');
			Symphony::Configuration()->write();
		} catch (Exception $ex) {
			
			return false;
		}
	}

	public function appendPreferences($context) {
		
		$group = new XMLElement('fieldset');
		$group->setAttribute('class', 'settings');
		$group->appendChild(new XMLElement('legend', 'Yubikey OTP Credentials'));

		$div = new XMLElement('div', NULL, array('class' => 'group'));
		
		$label = Widget::Label('Client ID');
		$label->appendChild(Widget::Input('settings[yubikey_2fa][clientId]', General::Sanitize($this->getClientId())));

		$div->appendChild($label);

		$label = Widget::Label('Secret');
                $label->appendChild(Widget::Input('settings[yubikey_2fa][secret]', General::Sanitize($this->getSecret()), 'password'));
		$div->appendChild($label);

		$group->appendChild($div);

		$group->appendChild(new XMLElement('p', 'Get an <a href="https://upgrade.yubico.com/getapikey/">API key</a> from Yubico.', array('class' => 'help')));

		$context['wrapper']->appendChild($group);
	}

	public function appendAuthorFields($context) {
		
		$group = $context['form']->getChildByName('fieldset', 1);
		$author = $context['author'];

		$label = new XMLElement('label', 'Yubikey ID');
		$input = new XMLElement('input', NULL, array('maxlength' => '12', 'value' => $this->getAuthorYubikeyID($author->get('id')), 'name' => 'fields[yubikey_id]'));
	
		$label->appendChild($input);
		$group->insertChildAt(3, $label);
	}

	public function insertYubikeyOTPField($context) {

		if ($context['oPage'] instanceOf contentLogin) {

			$form = $context['oPage']->Form;

			$form->getChildByName('fieldset',0)->appendChild(new XMLElement('label','Yubikey OTP'));
			$form->getChildByName('fieldset',0)->getChildByName('label', 2)->appendChild(
				new XMLElement('input', '', array(
					'type' => 'text',
					'name' => 'otp'
				))
			);
		}
	}

	public function validateOTP($context) {
		
		$clientId = $this->getClientId();
		$secretKey = $this->getSecret();

		$yubi = new Auth_Yubico($clientId, $secretKey);
		$otp = $_POST['otp'];

		// Check that author's Yubikey ID matches that given
		$authorId = Symphony::Author()->get('id');
		$yubikeyId = $this->getAuthorYubikeyID($authorId);
		if ($yubikeyId !== substr($otp, 0, 12)) {

			Symphony::logout();
		}
		// Verify OTP
		$auth = $yubi->verify($otp);

		if (PEAR::isError($auth)) {
			
			Symphony::logout();
		}
	}

	public function getClientId() {
		return Symphony::Configuration()->get('clientId', 'yubikey_2fa');
	}

	public function getSecret() {
		return Symphony::Configuration()->get('secret', 'yubikey_2fa');
	}

	public function getAuthorYubikeyID($authorId) {
	
		$query = 'SELECT yubikey_id FROM tbl_yubikey_2fa WHERE author_id = ' . $authorId;
		$result = Symphony::Database()->fetchRow(0, $query);

		return $result['yubikey_id'];
	}

	public function saveAuthorYubikeyId($context) {

		$yubikeyId = $_POST['fields']['yubikey_id'];
		$authorId = $context['author']->get('id');

		// Insert new yubikey
		return Symphony::Database()->insert(array('author_id' => $authorId, 'yubikey_id' => $yubikeyId), 'tbl_yubikey_2fa', true);
	}

}
