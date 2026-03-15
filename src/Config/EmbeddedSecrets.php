<?php
namespace RawatWP\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmbeddedSecrets {
	/**
	 * Get bundled encrypted malware-scan API key payload.
	 *
	 * @return array{iv:string,cipher:string}
	 */
	public static function get_malware_scan_seed() {
		return array(
			'iv'     => 'UcwUNdP9cmYLk/jCVc3VhQ==',
			'cipher' => 'QhUzoCpFZ+VnZKIqZ/k6X9h1b9QqNEzdLzRFJ0g9jbcl96vktt4e9IFSj3fYii1OH+ucDSy82gZk9xwyVQC991Fnvn5zTlyKWlqgqTQaMw8=',
		);
	}
}

