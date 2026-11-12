<?php
/**
 * Minimal WP_Error stand-in for unit tests.
 *
 * @package DalliDelivery
 */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in.
	 */
	class WP_Error {
		/**
		 * Message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 */
		public function __construct( $code = '', $message = '' ) {
			unset( $code );
			$this->message = $message;
		}

		/**
		 * Message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}
