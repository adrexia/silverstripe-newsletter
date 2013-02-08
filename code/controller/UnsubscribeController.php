<?php
/**
 * @package  newsletter
 */

/**
 * Create a form that a user can use to unsubscribe from a mailing list
 */
class UnsubscribeController extends Page_Controller {
	
	static public $days_unsubscribe_link_alive = 30;

	public static $allowed_actions = array(
		'index',
		'done',
		'undone',
		'resubscribe',
		'Form',
		'ResubscribeForm',
		'sendmeunsubscribelink',
		'linksent'
	);

	public static $url_handlers = array(
		'unsubscribe/$action' => 'UnsubscribeController'
	);

	function __construct($data = null) {
		parent::__construct($data);
	}

	function init() {
		parent::init();
		Requirements::css('newsletter/css/SubscriptionPage.css');
		Requirements::javascript(THIRDPARTY_DIR . '/jquery/jquery.js');
		Requirements::javascript(THIRDPARTY_DIR . '/jquery-validate/jquery.validate.min.js');
	}

	static public function set_days_unsubscribe_link_alive($days){
		self::$days_unsubscribe_link_alive = $days;
	}

	static public function get_days_unsubscribe_link_alive(){
		return self::$days_unsubscribe_link_alive;
	}

	function RelativeLink($action = null) {
		return "unsubscribe/$action";
	}

	private function getRecipient(){
		$validateHash = Convert::raw2sql($this->urlParams['ValidateHash']);
		if($validateHash) {
			$recipient = Recipient::get()->filter("ValidateHash", $validateHash);
			$now = date('Y-m-d H:i:s');
			if($now <= $recipient->ValidateHashExpired) return $recipient;
		}
	}
	
	private function getMailingLists($recipient = null){
		$validateHash = Convert::raw2sql($this->urlParams['ValidateHash']);
		$recipient = Recipient::get()->filter("ValidateHash", $validateHash);
		$siteConfig = DataObject::get_one("SiteConfig");
		if($siteConfig->GlobalUnsubscribe && $recipient){
			return $mailinglists = $recipient->MailingLists;
		}else{
			$mailinglistIDs = $this->urlParams['IDs'];
			if($mailinglistIDs) {
				return $mailinglists = DataList::create("MailingList")
					->where("\"ID\" in (" . Convert::raw2sql($mailinglistIDs) . ")");
			}
		}
	}

	private function getMailingListsByUnsubscribeRecords($recordIDs){
		$unsubscribeRecords = DataList::create("UnsubscribeRecord")
			->where("\"ID\" in (" . Convert::raw2sql($recordIDs) . ")");
		$mailinglists = new ArrayList();
		if($unsubscribeRecords->count()){
			foreach($unsubscribeRecords as $record){
				$list = DataObject::get_by_id("MailingList", $record->MailingListID);
				if($list && $list->exists()){
					$mailinglists->push($list);
				}
			}
		}
		return $mailinglists;
	}

	function index() {
		$recipient = $this->getRecipient();
		$mailinglists = $this->getMailingLists($recipient);
		if($recipient && $recipient->exists() && $mailinglists && $mailinglists->count()) {
			$unsubscribeRecordIDs = array();
			$this->unsubscribeFromLists($recipient, $mailinglists, $unsubscribeRecordIDs);
			$url = Director::absoluteBaseURL() . $this->RelativeLink('done') . "/" . $recipient->ValidateHash . "/" .
					implode(",", $unsubscribeRecordIDs);
			Controller::curr()->redirect($url, 302);
			return $url;
		}else{
			$listForm = $this->EmailAddressForm();
		/*	return $this->customise(array(
				'Title' => _t('Newsletter.INVALIDLINK', 'Invalid Link'),
				'Content' => _t('Newsletter.INVALIDUNSUBSCRIBECONTENT', 'This unsubscribe link is invalid')
			))->renderWith('Page');*/
		
			return $this->customise(array(
				'Content' => $listForm->forTemplate()
			))->renderWith('Page');
		}
    }

	function done() {
		$unsubscribeRecordIDs = $this->urlParams['IDs'];
		$hash = $this->urlParams['ID'];
		if($unsubscribeRecordIDs){
			$fields = new FieldList(
				new HiddenField("UnsubscribeRecordIDs", "", $unsubscribeRecordIDs),
				new HiddenField("Hash", "", $hash),
				new LiteralField("ResubscribeText",
					"Click the \"Resubscribe\" if you unsubscribed by accident and want to re-subscribe")
			);

			$actions = new FieldList(
				new FormAction("resubscribe", "Resubscribe")
			);

			$form = new Form($this, "ResubscribeForm", $fields, $actions);
			$form->setFormAction($this->Link('resubscribe'));
			$mailinglists = $this->getMailingListsByUnsubscribeRecords($unsubscribeRecordIDs);

			if($mailinglists && $mailinglists->count()){
				$listTitles = "";
				foreach($mailinglists as $list) {
					$listTitles .= "<li>".$list->Title."</li>";
				}
				$recipient = $this->getRecipient();
				$title = $recipient->FirstName?$recipient->FirstName:$recipient->Email;
				$content = sprintf(
					_t('Newsletter.UNSUBSCRIBEFROMLISTSSUCCESS',
						'<h3>Thank you, %s.</h3><br />You will no longer receive: %s.'),
					$title, 
					"<ul>".$listTitles."</ul>"
				);
			}else{
				$content = 
					_t('Newsletter.UNSUBSCRIBESUCCESS', 'Thank you.<br />You have been unsubscribed successfully');
			}
		}

		return $this->customise(array(
			'Title' => _t('UNSUBSCRIBEDTITLE', 'Unsubscribed'),
			'Content' => $content,
			'Form' => $form
		))->renderWith('Page');
	}

	function linksent(){
		$form = new Form($this, "UnsubscribeLinkSent", new FieldList(), new FieldList());
		
		if(isset($_GET['SendEmail']) && $_GET['SendEmail']){
			$form -> setMessage(sprintf(_t('Unsubscribe.LINKSENTTO', "The unsubscribe link has been sent to %s"), $_GET['SendEmail']), "good");
			return $this->customise(array(
	    		'Title' => _t('Unsubscribe.LINKSENT', 'Unsubscribe Link Sent'),
	    		'Form' => $form
	    	))->renderWith('Page');
		}elseif(isset($_GET['SendError']) && $_GET['SendError']){
			$form -> setMessage(sprintf(_t('Unsubscribe.LINKSENDERR', "Sorry, currently we have internal error, and can't send the unsubscribe link to %s"), $_GET['SendError']), "good");
			return $this->customise(array(
	    		'Title' => _t('Unsubscribe.LINKNOTSEND', 'Unsubscrib Link Can\'t Be Sent'),
	    		'Form' => $form
	    	))->renderWith('Page');
		}
	}

	/**
	* Display a form with all the mailing lists that the user is subscribed to
	*/
	function MailingListForm() {
		$member = $this->getMember();
		return new Unsubscribe_MailingListForm($this, 'MailingListForm', $member);
	}

	/**
	* Display a form allowing the user to input their email address
	*/
	function EmailAddressForm() {
		return new UnsubscribeEmailAddressForm( $this, 'EmailAddressForm' );
	}

	/**
	* Show the lists for the user with the given email address
	*/
	function sendmeunsubscribelink( $data) {
		if(isset($data['Email']) && $data['Email']) {
			$member = DataObject::get_one("Recipient", "Email = '".$data['Email']."'");
			if($member){
				if(!$from = Email::getAdminEmail()){
					$from = 'noreply@'.Director::BaseURL();
				}
				$to = $member->Email;
				$subject = "Unsubscribe Link";
				if($member->getHashText){
					
					$member->AutoLoginExpired = date('Y-m-d', time() + (86400 * 2));
					$member->write();
				}else{
					$member->generateValidateHashAndStore();
				}
				$link = Director::absoluteBaseURL() . $this->RelativeLink('index') ."/" . $member->AutoLoginHash;
				$membername = $member->getTitle();
				$body = $this->customise(array(
		    		'Content' => <<<HTML
Dear $membername,<br />
<p>Please click the link below to unsubscribe from our newsletters<br />
$link<br />
<br >
<br >
Thanks
</p>
HTML
		    	))->renderWith('UnsubscribeEmail', 'Page');
				$email = new Email($from, $to, $subject, $body);
				$result = $email -> send();
				if($result){
					Director::redirect(Director::absoluteBaseURL() . $this->RelativeLink('linksent') . "?SendEmail=".$data['Email']);
				}else{
					Director::redirect(Director::absoluteBaseURL() . $this->RelativeLink('linksent') . "?SendError=".$data['Email']);
				}
			}else{
				$form = $this->EmailAddressForm();
				$message = sprintf(_t("Unsubscribe.NOTSIGNUP", "Sorry, '%s' doesn't appear to be an sign-up member with us"), $data['Email']);
				$form->sessionMessage($message, 'bad');
				Director::redirectBack();
			}
		} else {
			$form = $this->EmailAddressForm();
			$message = _t("Unsubscribe.NOEMAILGIVEN", "Sorry, please type in a valid email address");
			$form->sessionMessage($message, 'bad');
			Director::redirectBack();
		}
	}

   /**
    * Unsubscribe the user from the given lists.
    */
	function resubscribe() {
		if(isset($_POST['Hash']) && isset($_POST['UnsubscribeRecordIDs'])){
			$recipient = DataObject::get_one(
				'Recipient', 
				"\"ValidateHash\" = '" . Convert::raw2sql($_POST['Hash']) . "'"
			);
			$mailinglists = $this->getMailingListsByUnsubscribeRecords($_POST['UnsubscribeRecordIDs']);
			if($recipient && $recipient->exists() && $mailinglists && $mailinglists->count()){
				$recipient->MailingLIsts()->addMany($mailinglists);
			}
			$url = Director::absoluteBaseURL() . $this->RelativeLink('undone') . "/" . $_POST['Hash']. "/" .
					$_POST['UnsubscribeRecordIDs'];
			Controller::curr()->redirect($url, 302);
			return $url;
		}else{
			return $this->customise(array(
				'Title' => _t('Newsletter.INVALIDRESUBSCRIBE', 'Invalid resubscrible'),
				'Content' => _t('Newsletter.INVALIDRESUBSCRIBECONTENT', 'This resubscribe link is invalid')
			))->renderWith('Page');
		}
	}

	function undone(){
		$recipient = $this->getRecipient();
		$mailinglists = $this->getMailingLists($recipient);

		if($mailinglists && $mailinglists->count()){
			$listTitles = "";
			foreach($mailinglists as $list) {
				$listTitles .= "<li>".$list->Title."</li>";
			}

			$title = $recipient->FirstName?$recipient->FirstName:$recipient->Email;
			$content = sprintf(
				_t('Newsletter.RESUBSCRIBEFROMLISTSSUCCESS',
					'<h3>Thank you. %s!</h3><br />You have been resubscribed to: %s.'),
				$title, 
				"<ul>".$listTitles."</ul>"
			);
		}else{
			$content = 
				_t('Newsletter.RESUBSCRIBESUCCESS', 'Thank you.<br />You have been resubscribed successfully');
		}

    	return $this->customise(array(
    		'Title' => _t('Newsletter.RESUBSCRIBED', 'Resubscribed'),
    		'Content' => $content,
    	))->renderWith('Page');
	}

	protected function unsubscribeFromLists($recipient, $lists, &$recordsIDs) {
		if($lists && $lists->count()){
			foreach($lists as $list){
				$recipient->Mailinglists()->remove($list);
				$unsubscribeRecord = new UnsubscribeRecord();
				$unsubscribeRecord->unsubscribe($recipient, $list);
				$recordsIDs[] = $unsubscribeRecord->ID;
			}
		}
  }
}

/**
 * 1st step form for the Unsubscribe page.
 * The form will let people enter an email address and press a button to continue.
 *
 * @package newsletter
 */
class UnsubscribeEmailAddressForm extends Form {

	function __construct( $controller, $name ) {

		$fields = new FieldList(
			new EmailField( 'Email', _t('Unsubscribe.EMAILADDR', 'Email address') )
		);

		$actions = new FieldList(
			new FormAction( 'sendmeunsubscribelink', _t('Unsubscribe.SENDMEUNSUBSCRIBELINK', 'Send me unsubscribe link'))
		);

		parent::__construct( $controller, $name, $fields, $actions, new RequiredFields(array('Email')));
		$this->disableSecurityToken();
	}

	function FormAction() {
		return $this->controller->RelativeLink('sendmeunsubscribelink') . "/?executeForm=" . $this->name;
	}
}

