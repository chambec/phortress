<?php
namespace Phortress;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;

/**
 * Namespace Continuation Environments: these are continuation of namespaces
 * for the purposes of variable declarations. When defining namespace-visible
 * identifiers, e.g constants or functions, this sets it on the actual
 * namespace.
 */
class NamespaceContinuationEnvironment extends NamespaceEnvironment {
	public function createFunction(Stmt $function) {
		return $this->getNamespace()->createFunction($function);
	}

	public function createClass(Class_ $class) {
		return $this->getNamespace()->createClass($class);
	}

	public function createNamespace(Namespace_ $namespace) {
		return $this->getNamespace()->createNamespace($namespace);
	}

	public function resolveFunction(Name $functionName) {
		return $this->getNamespace()->resolveFunction($functionName);
	}

	public function resolveClass(Name $className) {
		return $this->getNamespace()->resolveClass($className);
	}

	public function resolveNamespace(Name $namespaceName = null) {
		return $this->getNamespace()->resolveNamespace($namespaceName);
	}

	public function resolveConstant(Name $constantName) {
		return $this->getNamespace()->resolveConstant($constantName);
	}

	/**
	 * @inheritdoc
	 * @return NamespaceEnvironment
	 */
	public function getNamespace() {
		$parent = $this->getParent();
		while ($parent && get_class($parent) === 'Phortress\NamespaceContinuationEnvironment') {
			$parent = $parent->getParent();
		}

		assert($parent, 'NamespaceContinuationEnvironments must be enclosed by a ' .
			'NamespaceEnvironment');
		return $parent;
	}
}
