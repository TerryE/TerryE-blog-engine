<?php

/**
 * SMS using the TextLocal service
 * See the <a href="http://www.textlocal.com/developers/"> TextLocal Developers
 * API</a> for more details.  This a simple implementation which only supports
 * outbound SMS messages at this stage.  An example use is: \code
 * $sms  = new TextlocalSMS( 'terry@yourdomain.com', 
 *                           '1234567890eeeeee2323423423efeferf0123456' );
 * $resp = $sms->send( 'terry', '07555565432', 'Test message computer' ) ) );
 * \endcode
 */

class TextlocalSMS {

    private $uname;                       //< Authorisation UserName
    private $hash;                        //< Authorisation Password Hash
    const   service = 'http://www.txtlocal.com/';

    /**
	 * Constructor.
     * This establishes the SMS object and binds authorisation and session parameters.
	 * 
	 * @param $user  Your Messenger account's username.
	 * @param $pwd   Your Messenger accounts password hash.  Note that this isn't a 
     *               simple MD5 (it embeds a salt) so you need to get this from the 
     *               Text Local interactive code generator.
     * @param $json  Use JSON encoding for return info
     * @param $info  provide (non-JSON) return info
     */

	public function __construct( $user, $pwd, $json = TRUE, $info = TRUE  ) {
		$this->uname = $user;
		$this->hash	  = $pwd;
		$this->json   = $json ? 1 : 0;
		$this->info   = (!$json && $info) ? 1 : 0;
	}

	private function buildData( $params ) {
		// convert keyword data array into URL parameter string
		$pArray = array();
		foreach ( $params as $p => $v ) {
			$pArray[] = $p . "=" . urlencode( $v );
		}
		return implode( '&', $pArray );
	}    

    /**
	 * Send SMS message.
     * This POSTs your SMS message to \a sendsmspost.php at the service address.
     * @param $from    The "From Address" that is displayed when the message arrives 
     *                 on the handset. Can only be alpha numeric or space. 
     *                 Min 3 chars, max 11 chars. 
     * @param $to      The mobile number to send to
     * @param $message The text message body. This can be up to 612 characters in length. 
     *                 A single message is 160 characters, longer messages are 153 each 
     *                 (2=306,3=459,4=612). You can insert any merge data into this message 
     *                 from your database before submitting to Textlocal. To insert a 
     *                 newline character use %n.  Note: Euro symbols are treated as 
     *                 2 characters.
     * @param $test    If TRUE then messages will not be sent and credits will not be deducted.
     * @returns        If JSON then a keyed array containing the following fields:
     *                 - \b TestMode.  0 for live, 1 for testing.
     *                 - \b MessageReceived. The received message.
     *                 - \b ScheduledDate (optional if set). The received scheduled SMS time.
     *                 - \b Custom (optional if requested). The received custom ID to 
     *                   be passed back in receipt.
     *                 - \b From. The received SMS originator. Uses account default if blank.
     *                 - \b CreditsAvailable. Number of credits at start.
     *                 - \b MessageLength. Length of the message in characters.
     *                 - \b MessageCount. Number of messages (break at 160, 306, 459, 612).
     *                 - \b NumberContacts. The number of comma separted numbers.
     *                 - \b CreditsRequired. Credits required for job.
     *                 - \b CreditsRemaining. Credits remaining after the job.
     *                 - \b Error.  Any error messages generated. Omitted if no errors
     */

	public function send( $from, $to, $message, $test=FALSE ){

		$data = $this->buildData( array( 
			'uname'			=> $this->uname,
			'hash'			=> $this->hash,
			'message'		=> $message,
			'from'			=> $from,
			'selectednums'	=> $to,
			'info'			=> $this->info,
			'json'			=> $this->json,
			'test'			=> $test ? 1 : 0,
			) );

		$ch = curl_init(self::service . 'sendsmspost.php');
		curl_setopt_array ($ch, array( 
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $data,
			CURLOPT_RETURNTRANSFER => true,
			) );

		$result = curl_exec($ch); //This is the result from the API
		curl_close($ch);

		return $this->json ? json_decode( $result ) : $result;
	}

    /**
	 * Request credit balance.
     * This POSTs a credit balance request to \a getcredits.php at the service address.
     * @returns        an integer number of credits.
	 */
	public function checkCredit() {

		$data = $this->buildData( array( 
			'uname'			=> $this->uname,
			'hash'			=> $this->hash,
			) );

		$ch = curl_init(self::service . 'getcredits.php');
		curl_setopt_array($ch, array (
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $data,
			CURLOPT_RETURNTRANSFER => true,
			) );

		$credits = curl_exec($ch); 
		curl_close($ch);
		return $credits;  //This is the number of credits remaining
	}
}
