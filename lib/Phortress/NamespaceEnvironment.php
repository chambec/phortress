<?php
namespace Phortress;

use Phortress\Exception\UnboundIdentifierException;

class NamespaceEnvironment extends Environment {
	use EnvironmentHasFunctionsTrait;

	/**
	 * The namespaces declared in this namespace.
	 *
	 * @var array(string => NamespaceEnvironment)
	 */
	private $namespaces = array();

	/**
	 * The classes declared in this namespace.
	 *
	 * @var array(string => Environment)
	 */
	private $classes = array();

	/**
	 * Resolves the given namespace to an environment.
	 *
	 * @param string $namespaceName The name of the namespace to resolve. This
	 * can either be fully qualified, or relatively qualified.
	 * @return NamespaceEnvironment
	 * @throws UnboundIdentifierException When the identifier cannot be found.
	 */
	public function resolveNamespace($namespaceName) {
		if ($namespaceName === null) {
			return self;
		} else if (self::isAbsolutelyQualified($namespaceName)) {
			return $this->getGlobal()->resolveNamespace($namespaceName);
		} else if (self::isUnqualified($namespaceName)) {
			if (array_key_exists($namespaceName, $this->namespaces)) {
				return $this->namespaces[$namespaceName];
			} else {
				throw new UnboundIdentifierException($namespaceName, $this);
			}
		} else {
			list($nextNamespace, $namespaceName) =
				self::extractNamespaceComponent($namespaceName);
			return $this->resolveNamespace($nextNamespace)->
				resolveNamespace($namespaceName);
		}
	}

	public function resolveClass($className) {
		if (self::isAbsolutelyQualified($className)) {
			return $this->getGlobal()->resolveClass($className);
		} else if (self::isUnqualified($className)) {
			if (array_key_exists($className, $this->classes)) {
				return $this->classes[$className];
			} else {
				throw new UnboundIdentifierException($className, $this);
			}
		} else {
			list($nextNamespace, $className) =
				self::extractNamespaceComponent($className);
			return $this->resolveNamespace($nextNamespace)->
				resolveClass($className);
		}
	}

	public function resolveConstant($constantName) {
		if (self::isAbsolutelyQualified($constantName)) {
			return $this->getGlobal()->resolveConstant($constantName);
		} else {
			list($nextNamespace, $constantName) =
				self::extractNamespaceComponent($constantName);
			return $this->resolveNamespace($nextNamespace)->
				resolveConstant($constantName);
		}
	}

	/**
	 * Extracts the first namespace component from the given symbol, and returns
	 * the namespace and the tail of the symbol.
	 *
	 * @param string $symbol
	 * @return String[]
	 */
	private static function extractNamespaceComponent($symbol) {
		assert(!self::isAbsolutelyQualified($symbol));
		$firstSlash = strpos($symbol, '\\');
		if ($firstSlash === false) {
			return array(null, $symbol);
		} else {
			return array(
				substr($symbol, 0, $firstSlash),
				substr($symbol, $firstSlash + 1)
			);
		}
	}

	public function createChild() {
		$environment = new NamespaceContinuationEnvironment($this->name);
		$environment->parent = $this;

		return $environment;
	}

	/**
	 * Creates a new Child namespace.
	 *
	 * @param string $namespaceName The name of the namespace. This must be
	 * unqualified.
	 * @return NamespaceEnvironment The new namespace environment, with the
	 * parent properly set.
	 */
	public function createNamespace($namespaceName) {
		$result = new NamespaceEnvironment(sprintf('%s\%s',
			$this->name, $namespaceName));
		$result->parent = $this;

		return $result;
	}

	/**
	 * Copy the values by reference from one array to another.
	 */
	protected static function copyValueReferences($to, $from) {
		foreach ($from as $key => &$value) {
			$to[$key] = &$value;
		}
	}
}
