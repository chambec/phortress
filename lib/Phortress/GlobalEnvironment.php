<?php
namespace Phortress;

/**
 * The global environment for a program.
 *
 * @package Phortress
 */
class GlobalEnvironment extends NamespaceEnvironment {
	/**
	 * The superglobals for this environment.
	 *
	 * @var array
	 */
	private $superglobals;

	public function __construct() {
		parent::__construct('Global', null);

		$this->superglobals = array(
			'GLOBALS' => &$this->variables,
			'_SERVER' => array(),
			'_GET' => array(),
			'_POST' => array(),
			'_FILES' => array(),
			'_COOKIE' => array(),
			'_SESSION' => array(),
			'_REQUEST' => array(),
			'_ENV' => array()
		);

		self::copyValueReferences($this->variables, $this->superglobals);
	}

	public function getGlobal() {
		return $this;
	}

	/**
	 * Gets the superglobals in this global environment.
	 *
	 * @return array
	 */
	public function &getSuperglobals() {
		return $this->superglobals;
	}
}
