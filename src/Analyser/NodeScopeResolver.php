<?php declare(strict_types = 1);

namespace PHPStan\Analyser;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\StaticVar;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\Throw_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PHPStan\Broker\Broker;
use PHPStan\File\FileExcluder;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\PhpDocBlock;
use PHPStan\Type\ArrayType;
use PHPStan\Type\CommentHelper;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\MixedType;
use PHPStan\Type\NestedArrayItemType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypehintHelper;

class NodeScopeResolver
{

	/** @var \PHPStan\Broker\Broker */
	private $broker;

	/** @var \PHPStan\Parser\Parser */
	private $parser;

	/** @var \PhpParser\PrettyPrinter\Standard */
	private $printer;

	/** @var \PHPStan\Type\FileTypeMapper */
	private $fileTypeMapper;

	/** @var \PHPStan\File\FileExcluder */
	private $fileExcluder;

	/** @var \PhpParser\BuilderFactory */
	private $builderFactory;

	/** @var \PHPStan\File\FileHelper */
	private $fileHelper;

	/** @var bool */
	private $polluteScopeWithLoopInitialAssignments;

	/** @var bool */
	private $polluteCatchScopeWithTryAssignments;

	/** @var string[][] className(string) => methods(string[]) */
	private $earlyTerminatingMethodCalls;

	/** @var \PHPStan\Reflection\ClassReflection|null */
	private $anonymousClassReflection;

	/** @var bool[] filePath(string) => bool(true) */
	private $analysedFiles;

	public function __construct(
		Broker $broker,
		Parser $parser,
		\PhpParser\PrettyPrinter\Standard $printer,
		FileTypeMapper $fileTypeMapper,
		FileExcluder $fileExcluder,
		\PhpParser\BuilderFactory $builderFactory,
		FileHelper $fileHelper,
		bool $polluteScopeWithLoopInitialAssignments,
		bool $polluteCatchScopeWithTryAssignments,
		array $earlyTerminatingMethodCalls
	)
	{
		$this->broker = $broker;
		$this->parser = $parser;
		$this->printer = $printer;
		$this->fileTypeMapper = $fileTypeMapper;
		$this->fileExcluder = $fileExcluder;
		$this->builderFactory = $builderFactory;
		$this->fileHelper = $fileHelper;
		$this->polluteScopeWithLoopInitialAssignments = $polluteScopeWithLoopInitialAssignments;
		$this->polluteCatchScopeWithTryAssignments = $polluteCatchScopeWithTryAssignments;
		$this->earlyTerminatingMethodCalls = $earlyTerminatingMethodCalls;
	}

	/**
	 * @param string[] $files
	 */
	public function setAnalysedFiles(array $files)
	{
		$this->analysedFiles = array_fill_keys($files, true);
	}

	/**
	 * @param \PhpParser\Node[] $nodes
	 * @param \PHPStan\Analyser\Scope $scope
	 * @param \Closure $nodeCallback
	 * @param \PHPStan\Analyser\Scope $closureBindScope
	 */
	public function processNodes(
		array $nodes,
		Scope $scope,
		\Closure $nodeCallback,
		Scope $closureBindScope = null
	)
	{
		foreach ($nodes as $i => $node) {
			if (!($node instanceof \PhpParser\Node)) {
				continue;
			}

			if ($scope->getInFunctionCall() !== null && $node instanceof Arg) {
				$functionCall = $scope->getInFunctionCall();
				$value = $node->value;

				$parametersAcceptor = $this->findParametersAcceptorInFunctionCall($functionCall, $scope);

				if ($parametersAcceptor !== null) {
					$parameters = $parametersAcceptor->getParameters();
					$assignByReference = false;
					if (isset($parameters[$i])) {
						$assignByReference = $parameters[$i]->isPassedByReference();
					} elseif (count($parameters) > 0 && $parametersAcceptor->isVariadic()) {
						$lastParameter = $parameters[count($parameters) - 1];
						$assignByReference = $lastParameter->isPassedByReference();
					}
					if ($assignByReference && $value instanceof Variable && is_string($value->name)) {
						$scope = $scope->assignVariable($value->name, new MixedType());
					}
				}
			}

			$nodeScope = $scope;
			if ($i === 0 && $closureBindScope !== null) {
				$nodeScope = $closureBindScope;
			}

			$this->processNode($node, $nodeScope, $nodeCallback);
			$scope = $this->lookForAssigns($scope, $node);

			if ($node instanceof If_) {
				if ($this->findEarlyTermination($node->stmts, $scope) !== null) {
					$scope = $scope->filterByFalseyValue($node->cond);
					$this->processNode($node->cond, $scope, function (Node $node, Scope $inScope) use (&$scope) {
						$this->specifyFetchedPropertyForInnerScope($node, $inScope, true, $scope);
					});
				}
			} elseif ($node instanceof Node\Stmt\Declare_) {
				foreach ($node->declares as $declare) {
					if (
						$declare instanceof Node\Stmt\DeclareDeclare
						&& $declare->key === 'strict_types'
						&& $declare->value instanceof Node\Scalar\LNumber
						&& $declare->value->value === 1
					) {
						$scope = $scope->enterDeclareStrictTypes();
						break;
					}
				}
			} elseif (
				$node instanceof FuncCall
				&& $node->name instanceof Name
				&& (string) $node->name === 'assert'
				&& isset($node->args[0])
			) {
				$scope = $scope->filterByTruthyValue($node->args[0]->value);
			}
		}
	}

	private function specifyProperty(Scope $scope, Expr $expr): Scope
	{
		if ($expr instanceof PropertyFetch) {
			return $scope->specifyFetchedPropertyFromIsset($expr);
		} elseif (
			$expr instanceof Expr\StaticPropertyFetch
			&& $expr->class instanceof Name
			&& (string) $expr->class === 'static'
		) {
			return $scope->specifyFetchedStaticPropertyFromIsset($expr);
		}

		return $scope;
	}

	private function specifyFetchedPropertyForInnerScope(Node $node, Scope $inScope, bool $inEarlyTermination, Scope &$scope)
	{
		if ($inEarlyTermination === $inScope->isNegated()) {
			if ($node instanceof Isset_) {
				foreach ($node->vars as $var) {
					$scope = $this->specifyProperty($scope, $var);
				}
			}
		} else {
			if ($node instanceof Expr\Empty_) {
				$scope = $this->specifyProperty($scope, $node->expr);
				$scope = $this->assignVariable($scope, $node->expr);
			}
		}
	}

	private function lookForArrayDestructuringArray(Scope $scope, Node $node): Scope
	{
		if ($node instanceof Array_) {
			foreach ($node->items as $item) {
				if ($item === null) {
					continue;
				}
				$scope = $this->lookForArrayDestructuringArray($scope, $item->value);
			}
		} elseif ($node instanceof Variable && is_string($node->name)) {
			$scope = $scope->assignVariable($node->name);
		} elseif ($node instanceof ArrayDimFetch && $node->var instanceof Variable && is_string($node->var->name)) {
			$scope = $scope->assignVariable($node->var->name);
		} elseif ($node instanceof List_) {
			foreach ($node->items as $item) {
				/** @var \PhpParser\Node\Expr\ArrayItem|null $itemValue */
				$itemValue = $item;
				if ($itemValue === null) {
					continue;
				}
				$itemValue = $itemValue->value;
				if ($itemValue instanceof Variable && is_string($itemValue->name)) {
					$scope = $scope->assignVariable($itemValue->name);
				} else {
					$scope = $this->lookForArrayDestructuringArray($scope, $itemValue);
				}
			}
		}

		return $scope;
	}

	private function enterForeach(Scope $scope, Foreach_ $node): Scope
	{
		if ($node->keyVar !== null && $node->keyVar instanceof Variable && is_string($node->keyVar->name)) {
			$scope = $scope->assignVariable($node->keyVar->name);
		}

		if ($node->valueVar instanceof Variable && is_string($node->valueVar->name)) {
			$scope = $scope->enterForeach(
				$node->expr,
				$node->valueVar->name,
				$node->keyVar !== null
				&& $node->keyVar instanceof Variable
				&& is_string($node->keyVar->name)
					? $node->keyVar->name
					: null
			);
		}

		if ($node->valueVar instanceof List_ || $node->valueVar instanceof Array_) {
			$scope = $this->lookForArrayDestructuringArray($scope, $node->valueVar);
		}

		return $this->lookForAssigns($scope, $node->valueVar);
	}

	private function processNode(\PhpParser\Node $node, Scope $scope, \Closure $nodeCallback)
	{
		$nodeCallback($node, $scope);

		if (
			$node instanceof \PhpParser\Node\Stmt\ClassLike
		) {
			if ($node instanceof Node\Stmt\Trait_) {
				return;
			}
			if (isset($node->namespacedName)) {
				$scope = $scope->enterClass($this->broker->getClass((string) $node->namespacedName));
			} elseif ($this->anonymousClassReflection !== null) {
				$scope = $scope->enterAnonymousClass($this->anonymousClassReflection);
			} else {
				throw new \PHPStan\ShouldNotHappenException();
			}
		} elseif ($node instanceof Node\Stmt\TraitUse) {
			$this->processTraitUse($node, $scope, $nodeCallback);
		} elseif ($node instanceof \PhpParser\Node\Stmt\Function_) {
			$scope = $this->enterFunction($scope, $node);
		} elseif ($node instanceof \PhpParser\Node\Stmt\ClassMethod) {
			$scope = $this->enterClassMethod($scope, $node);
		} elseif ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
			$scope = $scope->enterNamespace((string) $node->name);
		} elseif (
			$node instanceof \PhpParser\Node\Expr\StaticCall
			&& (is_string($node->class) || $node->class instanceof \PhpParser\Node\Name)
			&& is_string($node->name)
			&& (string) $node->class === 'Closure'
			&& $node->name === 'bind'
		) {
			$thisType = null;
			if (isset($node->args[1])) {
				$argValue = $node->args[1]->value;
				if ($argValue instanceof Expr\ConstFetch && ((string) $argValue->name === 'null')) {
					$thisType = null;
				} else {
					$thisType = $scope->getType($argValue);
				}
			}
			$scopeClass = 'static';
			if (isset($node->args[2])) {
				$argValue = $node->args[2]->value;
				$argValueType = $scope->getType($argValue);
				if ($argValueType->getClass() !== null) {
					$scopeClass = $argValueType->getClass();
				} elseif (
					$argValue instanceof Expr\ClassConstFetch
					&& $argValue->name === 'class'
					&& $argValue->class instanceof Name
				) {
					$scopeClass = $scope->resolveName($argValue->class);
				} elseif ($argValue instanceof Node\Scalar\String_) {
					$scopeClass = $argValue->value;
				}
			}
			$closureBindScope = $scope->enterClosureBind($thisType, $scopeClass);
		} elseif ($node instanceof \PhpParser\Node\Expr\Closure) {
			$scope = $scope->enterAnonymousFunction($node->params, $node->uses, $node->returnType);
		} elseif ($node instanceof Foreach_) {
			$scope = $this->enterForeach($scope, $node);
		} elseif ($node instanceof Catch_) {
			$scope = $scope->enterCatch(
				$node->types,
				$node->var
			);
		} elseif ($node instanceof For_) {
			foreach ($node->init as $initExpr) {
				$scope = $this->lookForAssigns($scope, $initExpr);
			}

			foreach ($node->cond as $condExpr) {
				$scope = $this->lookForAssigns($scope, $condExpr);
			}

			foreach ($node->loop as $loopExpr) {
				$scope = $this->lookForAssigns($scope, $loopExpr);
			}
		} elseif ($node instanceof Array_) {
			$scope = $scope->exitFirstLevelStatements();
			foreach ($node->items as $item) {
				if ($item === null) {
					continue;
				}
				$this->processNode($item, $scope, $nodeCallback);
				if ($item->key !== null) {
					$scope = $this->lookForAssigns($scope, $item->key);
				}
				$scope = $this->lookForAssigns($scope, $item->value);
			}

			return;
		} elseif ($node instanceof If_) {
			$scope = $this->lookForAssigns($scope, $node->cond)->exitFirstLevelStatements();
			$ifScope = $scope;
			$this->processNode($node->cond, $scope, $nodeCallback);
			$scope = $scope->filterByTruthyValue($node->cond);

			$specifyFetchedProperty = function (Node $node, Scope $inScope) use (&$scope) {
				$this->specifyFetchedPropertyForInnerScope($node, $inScope, false, $scope);
			};
			$this->processNode($node->cond, $scope, $specifyFetchedProperty);
			$this->processNodes($node->stmts, $scope->enterFirstLevelStatements(), $nodeCallback);

			$elseifScope = $ifScope->filterByFalseyValue($node->cond);
			foreach ($node->elseifs as $elseif) {
				$scope = $elseifScope;
				$scope = $this->lookForAssigns($scope, $elseif->cond)->exitFirstLevelStatements();
				$this->processNode($elseif->cond, $scope, $nodeCallback);
				$scope = $scope->filterByTruthyValue($elseif->cond);
				$this->processNode($elseif->cond, $scope, $specifyFetchedProperty);
				$this->processNodes($elseif->stmts, $scope->enterFirstLevelStatements(), $nodeCallback);
				$elseifScope = $this->lookForAssigns($elseifScope, $elseif->cond)
					->filterByFalseyValue($elseif->cond);
			}
			if ($node->else !== null) {
				$this->processNode($node->else, $elseifScope, $nodeCallback);
			}

			return;
		} elseif ($node instanceof Switch_) {
			$scope = $scope->exitFirstLevelStatements();
			$this->processNode($node->cond, $scope, $nodeCallback);
			$scope = $this->lookForAssigns($scope, $node->cond);
			$switchScope = $scope;
			$switchConditionIsTrue = $node->cond instanceof Expr\ConstFetch && strtolower((string) $node->cond->name) === 'true';
			$switchConditionGetClassExpression = null;
			if (
				$node->cond instanceof FuncCall
				&& $node->cond->name instanceof Name
				&& strtolower((string) $node->cond->name) === 'get_class'
				&& isset($node->cond->args[0])
			) {
				$switchConditionGetClassExpression = $node->cond->args[0]->value;
			}
			foreach ($node->cases as $caseNode) {
				if ($caseNode->cond !== null) {
					$switchScope = $this->lookForAssigns($switchScope, $caseNode->cond);

					if ($switchConditionIsTrue) {
						$switchScope = $switchScope->filterByTruthyValue($caseNode->cond);
					} elseif (
						$switchConditionGetClassExpression !== null
						&& $caseNode->cond instanceof Expr\ClassConstFetch
						&& $caseNode->cond->class instanceof Name
						&& strtolower($caseNode->cond->name) === 'class'
					) {
						$switchScope = $switchScope->specifyExpressionType(
							$switchConditionGetClassExpression,
							new ObjectType((string) $caseNode->cond->class)
						);
					}
				}
				$this->processNode($caseNode, $switchScope, $nodeCallback);
				if ($this->findEarlyTermination($caseNode->stmts, $switchScope) !== null) {
					$switchScope = $scope;
				}
			}
			return;
		} elseif ($node instanceof While_) {
			$scope = $this->lookForAssigns($scope, $node->cond);
		} elseif ($node instanceof TryCatch) {
			$statements = [];
			$this->processNodes($node->stmts, $scope->enterFirstLevelStatements(), $nodeCallback);

			$scopeForLookForAssignsInBranches = $scope;
			if ($this->polluteCatchScopeWithTryAssignments) {
				foreach ($node->stmts as $statement) {
					$scope = $this->lookForAssigns($scope, $statement);
				}
			}

			if ($node->finally !== null) {
				$statements[] = new StatementList($scope, $node->stmts);
			}

			foreach ($node->catches as $catch) {
				$this->processNode($catch, $scope, $nodeCallback);
				if ($node->finally !== null) {
					$statements[] = new StatementList($scope->enterCatch(
						$catch->types,
						$catch->var
					), $catch->stmts);
				}
			}

			if ($node->finally !== null) {
				$finallyScope = $this->lookForAssignsInBranches($scopeForLookForAssignsInBranches, $statements, false, false);

				$this->processNode($node->finally, $finallyScope, $nodeCallback);
			}

			return;
		} elseif ($node instanceof Ternary) {
			$scope = $this->lookForAssigns($scope, $node->cond);
		} elseif ($node instanceof Do_) {
			foreach ($node->stmts as $statement) {
				$scope = $this->lookForAssigns($scope, $statement);
			}
		} elseif ($node instanceof FuncCall) {
			$scope = $scope->enterFunctionCall($node);
		} elseif ($node instanceof Expr\StaticCall) {
			$scope = $scope->enterFunctionCall($node);
		} elseif ($node instanceof MethodCall) {
			if (
				$scope->getType($node->var)->getClass() === 'Closure'
				&& $node->name === 'call'
				&& isset($node->args[0])
			) {
				$closureCallScope = $scope->enterClosureBind($scope->getType($node->args[0]->value), 'static');
			}
			$scope = $scope->enterFunctionCall($node);
		} elseif ($node instanceof Array_) {
			foreach ($node->items as $item) {
				if ($item === null) {
					continue;
				}
				$scope = $this->lookForAssigns($scope, $item->value);
			}
		} elseif ($node instanceof New_ && $node->class instanceof Class_) {
			$node->args = [];
			foreach ($node->class->stmts as $i => $statement) {
				if (
					$statement instanceof Node\Stmt\ClassMethod
					&& $statement->name === '__construct'
				) {
					unset($node->class->stmts[$i]);
					$node->class->stmts = array_values($node->class->stmts);
					break;
				}
			}

			$node->class->stmts[] = $this->builderFactory
				->method('__construct')
				->makePublic()
				->getNode();

			$code = $this->printer->prettyPrint([$node]);
			$classReflection = new \ReflectionClass(eval(sprintf('return %s', $code)));
			$this->anonymousClassReflection = $this->broker->getClassFromReflection(
				$classReflection,
				sprintf('class@anonymous%s:%s', $scope->getFile(), $node->getLine())
			);
		} elseif ($node instanceof BooleanNot) {
			$scope = $scope->enterNegation();
		} elseif ($node instanceof Unset_ || $node instanceof Isset_) {
			foreach ($node->vars as $unsetVar) {
				$scope = $scope->enterExpressionAssign($unsetVar);
			}
		}

		$originalScope = $scope;
		foreach ($node->getSubNodeNames() as $subNodeName) {
			$scope = $originalScope;
			$subNode = $node->{$subNodeName};

			if (is_array($subNode)) {
				$argClosureBindScope = null;
				if (isset($closureBindScope) && $subNodeName === 'args') {
					$argClosureBindScope = $closureBindScope;
				}
				if ($subNodeName === 'stmts') {
					$scope = $scope->enterFirstLevelStatements();
				} else {
					$scope = $scope->exitFirstLevelStatements();
				}

				if ($node instanceof Foreach_ && $subNodeName === 'stmts') {
					$scope = $this->lookForAssigns($scope, $node->expr);
				}
				if ($node instanceof While_ && $subNodeName === 'stmts') {
					$scope = $scope->filterByTruthyValue($node->cond);
				}

				if ($node instanceof Isset_ && $subNodeName === 'vars') {
					foreach ($node->vars as $issetVar) {
						$scope = $this->specifyProperty($scope, $issetVar);
					}
				}

				if ($node instanceof MethodCall && $subNodeName === 'args') {
					$scope = $this->lookForAssigns($scope, $node->var);
				}

				$this->processNodes($subNode, $scope, $nodeCallback, $argClosureBindScope);
			} elseif ($subNode instanceof \PhpParser\Node) {
				if ($node instanceof Coalesce && $subNodeName === 'left') {
					$scope = $this->assignVariable($scope, $subNode);
				}

				if ($node instanceof Ternary) {
					if ($subNodeName === 'if') {
						$scope = $scope->filterByTruthyValue($node->cond);
						$this->processNode($node->cond, $scope, function (Node $node, Scope $inScope) use (&$scope) {
							$this->specifyFetchedPropertyForInnerScope($node, $inScope, false, $scope);
						});
					} elseif ($subNodeName === 'else') {
						$scope = $scope->filterByFalseyValue($node->cond);
						$this->processNode($node->cond, $scope, function (Node $node, Scope $inScope) use (&$scope) {
							$this->specifyFetchedPropertyForInnerScope($node, $inScope, true, $scope);
						});
					}
				}

				if ($node instanceof BooleanAnd && $subNodeName === 'right') {
					$scope = $scope->filterByTruthyValue($node->left);
				}
				if ($node instanceof BooleanOr && $subNodeName === 'right') {
					$scope = $scope->filterByFalseyValue($node->left);
				}

				if (($node instanceof Assign || $node instanceof AssignRef) && $subNodeName === 'var') {
					$scope = $this->lookForEnterVariableAssign($scope, $node->var);
				}

				if ($node instanceof BinaryOp && $subNodeName === 'right') {
					$scope = $this->lookForAssigns($scope, $node->left);
				}

				if ($node instanceof Expr\Empty_ && $subNodeName === 'expr') {
					$scope = $this->specifyProperty($scope, $node->expr);
					$scope = $this->lookForEnterVariableAssign($scope, $node->expr);
				}

				if (
					$node instanceof ArrayItem
					&& $subNodeName === 'value'
					&& $node->key !== null
				) {
					$scope = $this->lookForAssigns($scope, $node->key);
				}

				$nodeScope = $scope->exitFirstLevelStatements();
				if ($scope->isInFirstLevelStatement()) {
					if ($node instanceof Ternary && $subNodeName !== 'cond') {
						$nodeScope = $scope->enterFirstLevelStatements();
					} elseif (
						($node instanceof BooleanAnd || $node instanceof BinaryOp\BooleanOr)
						&& $subNodeName === 'right'
					) {
						$nodeScope = $scope->enterFirstLevelStatements();
					}
				}

				if ($node instanceof MethodCall && $subNodeName === 'var' && isset($closureCallScope)) {
					$nodeScope = $closureCallScope->exitFirstLevelStatements();
				}

				$this->processNode($subNode, $nodeScope, $nodeCallback);
			}
		}
	}

	private function lookForEnterVariableAssign(Scope $scope, Expr $node): Scope
	{
		if ($node instanceof Variable) {
			$scope = $scope->enterExpressionAssign($node);
		} elseif ($node instanceof ArrayDimFetch) {
			while ($node instanceof ArrayDimFetch) {
				$node = $node->var;
			}

			if ($node instanceof Variable) {
				$scope = $scope->enterExpressionAssign($node);
			}
		} elseif ($node instanceof List_ || $node instanceof Array_) {
			foreach ($node->items as $listItem) {
				if ($listItem === null) {
					continue;
				}
				$listItemValue = $listItem;
				if ($listItemValue instanceof Expr\ArrayItem) {
					$listItemValue = $listItemValue->value;
				}
				$scope = $this->lookForEnterVariableAssign($scope, $listItemValue);
			}
		} else {
			$scope = $scope->enterExpressionAssign($node);
		}

		return $scope;
	}

	private function lookForAssigns(Scope $scope, \PhpParser\Node $node): Scope
	{
		if ($node instanceof StaticVar) {
			$scope = $scope->assignVariable($node->name, $node->default !== null ? $scope->getType($node->default) : null);
		} elseif ($node instanceof Static_) {
			foreach ($node->vars as $var) {
				$scope = $this->lookForAssigns($scope, $var);
			}
		} elseif ($node instanceof If_) {
			$scope = $this->lookForAssigns($scope, $node->cond);
			$ifStatement = new StatementList(
				$scope->filterByTruthyValue($node->cond),
				array_merge([$node->cond], $node->stmts)
			);

			$elseIfScope = $scope->filterByFalseyValue($node->cond);
			$elseIfStatements = [];
			foreach ($node->elseifs as $elseIf) {
				$elseIfStatements[] = new StatementList($elseIfScope, array_merge([$elseIf->cond], $elseIf->stmts));
				$elseIfScope = $elseIfScope->filterByFalseyValue($elseIf->cond);
			}

			$statements = [
				$ifStatement,
				new StatementList($elseIfScope, $node->else !== null ? $node->else->stmts : []),
			];
			$statements = array_merge($statements, $elseIfStatements);

			$scope = $this->lookForAssignsInBranches($scope, $statements);
		} elseif ($node instanceof TryCatch) {
			$statements = [
				new StatementList($scope, $node->stmts),
			];
			foreach ($node->catches as $catch) {
				$statements[] = new StatementList($scope->enterCatch(
					$catch->types,
					$catch->var
				), $catch->stmts);
			}

			$scope = $this->lookForAssignsInBranches($scope, $statements);
			if ($node->finally !== null) {
				foreach ($node->finally->stmts as $statement) {
					$scope = $this->lookForAssigns($scope, $statement);
				}
			}
		} elseif ($node instanceof MethodCall || $node instanceof FuncCall || $node instanceof Expr\StaticCall) {
			if ($node instanceof MethodCall) {
				$scope = $this->lookForAssigns($scope, $node->var);
			}
			foreach ($node->args as $argument) {
				$scope = $this->lookForAssigns($scope, $argument);
			}

			$parametersAcceptor = $this->findParametersAcceptorInFunctionCall($node, $scope);

			if ($parametersAcceptor !== null) {
				$parameters = $parametersAcceptor->getParameters();
				foreach ($node->args as $i => $arg) {
					$assignByReference = false;
					if (isset($parameters[$i])) {
						$assignByReference = $parameters[$i]->isPassedByReference();
					} elseif (count($parameters) > 0 && $parametersAcceptor->isVariadic()) {
						$lastParameter = $parameters[count($parameters) - 1];
						$assignByReference = $lastParameter->isPassedByReference();
					}

					if (!$assignByReference) {
						continue;
					}

					$arg = $node->args[$i]->value;
					if ($arg instanceof Variable && is_string($arg->name)) {
						$scope = $scope->assignVariable($arg->name, new MixedType());
					}
				}
			}
			if (
				$node instanceof FuncCall
				&& $node->name instanceof Name
				&& in_array((string) $node->name, [
					'fopen',
					'file_get_contents',
				], true)
			) {
				$scope = $scope->assignVariable('http_response_header', new ArrayType(new StringType(), false));
			}
		} elseif ($node instanceof BinaryOp) {
			$scope = $this->lookForAssigns($scope, $node->left);
			$scope = $this->lookForAssigns($scope, $node->right);
		} elseif ($node instanceof Arg) {
			$scope = $this->lookForAssigns($scope, $node->value);
		} elseif ($node instanceof BooleanNot) {
			$scope = $this->lookForAssigns($scope, $node->expr);
		} elseif ($node instanceof Ternary) {
			$scope = $this->lookForAssigns($scope, $node->cond);
		} elseif ($node instanceof Array_) {
			foreach ($node->items as $item) {
				if ($item === null) {
					continue;
				}
				if ($item->key !== null) {
					$scope = $this->lookForAssigns($scope, $item->key);
				}
				$scope = $this->lookForAssigns($scope, $item->value);
			}
		} elseif ($node instanceof New_) {
			foreach ($node->args as $arg) {
				$scope = $this->lookForAssigns($scope, $arg);
			}
		} elseif ($node instanceof Do_) {
			foreach ($node->stmts as $statement) {
				$scope = $this->lookForAssigns($scope, $statement);
			}
		} elseif ($node instanceof Switch_) {
			$statements = [];
			$hasDefault = false;
			foreach ($node->cases as $case) {
				if ($case->cond === null) {
					$hasDefault = true;
				}
				$statements[] = new StatementList($scope, $case->stmts);
			}

			if (!$hasDefault) {
				$statements[] = new StatementList($scope, []);
			}

			$scope = $this->lookForAssignsInBranches($scope, $statements, true);
		} elseif ($node instanceof Cast) {
			$scope = $this->lookForAssigns($scope, $node->expr);
		} elseif ($node instanceof For_) {
			if ($this->polluteScopeWithLoopInitialAssignments) {
				foreach ($node->init as $initExpr) {
					$scope = $this->lookForAssigns($scope, $initExpr);
				}

				foreach ($node->cond as $condExpr) {
					$scope = $this->lookForAssigns($scope, $condExpr);
				}
			}

			$statements = [
				new StatementList($scope, $node->stmts),
				new StatementList($scope, []), // in order not to add variables existing only inside the for loop
			];
			$scope = $this->lookForAssignsInBranches($scope, $statements);
		} elseif ($node instanceof While_) {
			if ($this->polluteScopeWithLoopInitialAssignments) {
				$scope = $this->lookForAssigns($scope, $node->cond);
			}

			$statements = [
				new StatementList($scope, $node->stmts),
				new StatementList($scope, []), // in order not to add variables existing only inside the for loop
			];
			$scope = $this->lookForAssignsInBranches($scope, $statements);
		} elseif ($node instanceof ErrorSuppress) {
			$scope = $this->lookForAssigns($scope, $node->expr);
		} elseif ($node instanceof \PhpParser\Node\Stmt\Unset_) {
			foreach ($node->vars as $var) {
				if ($var instanceof Variable && is_string($var->name)) {
					$scope = $scope->unsetVariable($var->name);
				}
			}
		} elseif ($node instanceof Echo_) {
			foreach ($node->exprs as $echoedExpr) {
				$scope = $this->lookForAssigns($scope, $echoedExpr);
			}
		} elseif ($node instanceof Print_) {
			$scope = $this->lookForAssigns($scope, $node->expr);
		} elseif ($node instanceof Foreach_) {
			$scope = $this->lookForAssigns($scope, $node->expr);
			$initialScope = $scope;
			$scope = $this->enterForeach($scope, $node);
			$statements = [
				new StatementList($scope, $node->stmts),
				new StatementList($scope, []), // in order not to add variables existing only inside the for loop
			];
			$scope = $this->lookForAssignsInBranches($initialScope, $statements);
		} elseif ($node instanceof Isset_) {
			foreach ($node->vars as $var) {
				$scope = $this->lookForAssigns($scope, $var);
			}
		} elseif ($node instanceof Expr\Empty_) {
			$scope = $this->lookForAssigns($scope, $node->expr);
		} elseif ($node instanceof ArrayDimFetch && $node->dim !== null) {
			$scope = $this->lookForAssigns($scope, $node->dim);
		} elseif ($node instanceof Expr\Closure) {
			foreach ($node->uses as $closureUse) {
				if (!$closureUse->byRef || $scope->hasVariableType($closureUse->var)) {
					continue;
				}

				$scope = $scope->assignVariable($closureUse->var, new MixedType());
			}
		} elseif ($node instanceof Instanceof_) {
			$scope = $this->lookForAssigns($scope, $node->expr);
		}

		$scope = $this->updateScopeForVariableAssign($scope, $node);

		return $scope;
	}

	private function updateScopeForVariableAssign(Scope $scope, \PhpParser\Node $node): Scope
	{
		if ($node instanceof Assign || $node instanceof AssignRef || $node instanceof Isset_ || $node instanceof Expr\AssignOp) {
			if ($node instanceof Assign || $node instanceof AssignRef || $node instanceof Expr\AssignOp) {
				$vars = [$node->var];
			} elseif ($node instanceof Isset_) {
				$vars = $node->vars;
			} else {
				throw new \PHPStan\ShouldNotHappenException();
			}

			foreach ($vars as $var) {
				$type = null;
				if ($node instanceof Assign || $node instanceof AssignRef) {
					$type = $scope->getType($node->expr);
				} elseif ($node instanceof Expr\AssignOp) {
					if (
						$node->var instanceof Variable
						&& is_string($node->var->name)
						&& !$scope->hasVariableType($node->var->name)
					) {
						continue;
					}
					$type = $scope->getType($node);
				}
				$scope = $this->assignVariable($scope, $var, $type);
			}

			if ($node instanceof Assign || $node instanceof AssignRef) {
				if ($node->var instanceof Array_ || $node->var instanceof List_) {
					$scope = $this->lookForArrayDestructuringArray($scope, $node->var);
				}
			}

			if (!$node instanceof Isset_) {
				$scope = $this->lookForAssigns($scope, $node->expr);
			}

			if ($node instanceof Assign || $node instanceof AssignRef) {
				$comment = CommentHelper::getDocComment($node);
				if ($comment !== null && $node->var instanceof Variable && is_string($node->var->name)) {
					$variableName = $node->var->name;
					$processVarAnnotation = function (string $matchedType, string $matchedVariableName) use ($scope, $variableName): Scope {
						$fileTypeMap = $this->fileTypeMapper->getTypeMap($scope->getFile());
						if (isset($fileTypeMap[$matchedType]) && $matchedVariableName === $variableName) {
							return $scope->assignVariable($matchedVariableName, $fileTypeMap[$matchedType]);
						}

						return $scope;
					};

					if (preg_match('#@var\s+' . FileTypeMapper::TYPE_PATTERN . '\s+\$([a-zA-Z0-9_]+)#', $comment, $matches)) {
						$scope = $processVarAnnotation($matches[1], $matches[2]);
					} elseif (preg_match('#@var\s+\$([a-zA-Z0-9_]+)\s+' . FileTypeMapper::TYPE_PATTERN . '#', $comment, $matches)) {
						$scope = $processVarAnnotation($matches[2], $matches[1]);
					} elseif (preg_match('#@var\s+' . FileTypeMapper::TYPE_PATTERN . '(?!\s+\$[a-zA-Z0-9_]+)#', $comment, $matches)) {
						$scope = $processVarAnnotation($matches[1], $variableName);
					}
				}
			}
		}

		return $scope;
	}

	private function assignVariable(Scope $scope, Node $var, Type $subNodeType = null): Scope
	{
		if ($var instanceof Variable && is_string($var->name)) {
			$scope = $scope->assignVariable($var->name, $subNodeType);
		} elseif ($var instanceof ArrayDimFetch) {
			$depth = 0;
			while ($var instanceof ArrayDimFetch) {
				$var = $var->var;
				$depth++;
			}

			if (isset($var->dim)) {
				$scope = $this->lookForAssigns($scope, $var->dim);
			}

			if ($var instanceof Variable && is_string($var->name)) {
				if ($scope->hasVariableType($var->name)) {
					$arrayDimFetchVariableType = $scope->getVariableType($var->name);
					if (
						!$arrayDimFetchVariableType instanceof ArrayType
						&& !$arrayDimFetchVariableType instanceof NullType
						&& !$arrayDimFetchVariableType instanceof MixedType
					) {
						return $scope;
					}
				}
				$arrayType = ArrayType::createDeepArrayType(
					new NestedArrayItemType($subNodeType !== null ? $subNodeType : new MixedType(), $depth),
					false
				);
				if ($scope->hasVariableType($var->name)) {
					if ($scope->getVariableType($var->name) instanceof ArrayType) {
						$arrayType = $scope->getVariableType($var->name)->combineWith($arrayType);
					}
				}

				$scope = $scope->assignVariable($var->name, $arrayType);
			}
		} elseif ($var instanceof PropertyFetch && $subNodeType !== null) {
			$scope = $scope->specifyExpressionType($var, $subNodeType);
		} elseif ($var instanceof Expr\StaticPropertyFetch && $subNodeType !== null) {
			$scope = $scope->specifyExpressionType($var, $subNodeType);
		} else {
			$scope = $this->lookForAssigns($scope, $var);
		}

		return $scope;
	}

	/**
	 * @param \PHPStan\Analyser\Scope $initialScope
	 * @param \PHPStan\Analyser\StatementList[] $statementsLists
	 * @param bool $isSwitchCase
	 * @param bool $respectEarlyTermination
	 * @return Scope
	 */
	private function lookForAssignsInBranches(
		Scope $initialScope,
		array $statementsLists,
		bool $isSwitchCase = false,
		bool $respectEarlyTermination = true
	): Scope
	{
		/** @var \PHPStan\Analyser\Scope|null $intersectedScope */
		$intersectedScope = null;

		/** @var \PHPStan\Analyser\Scope|null $allBranchesScope */
		$allBranchesScope = null;

		/** @var \PHPStan\Analyser\Scope|null $previousBranchScope */
		$previousBranchScope = null;

		foreach ($statementsLists as $i => $statementList) {
			$statements = $statementList->getStatements();
			$branchScope = $statementList->getScope();
			$branchScopeWithInitialScopeRemoved = $branchScope->removeVariables($initialScope, true);

			$earlyTerminationStatement = null;
			foreach ($statements as $statement) {
				$branchScope = $this->lookForAssigns($branchScope, $statement);
				$branchScopeWithInitialScopeRemoved = $branchScope->removeVariables($initialScope, false);
				$earlyTerminationStatement = $this->findStatementEarlyTermination($statement, $branchScope);
				if ($earlyTerminationStatement !== null) {
					if (!$isSwitchCase && $respectEarlyTermination) {
						continue 2;
					}
					break;
				}
			}

			if ($earlyTerminationStatement === null || $earlyTerminationStatement instanceof Break_ || !$respectEarlyTermination) {
				if ($intersectedScope === null) {
					$intersectedScope = $initialScope->createIntersectedScope($branchScopeWithInitialScopeRemoved);
				}
				if ($allBranchesScope === null) {
					$allBranchesScope = $initialScope->createIntersectedScope($branchScopeWithInitialScopeRemoved);
				}

				if ($isSwitchCase && $previousBranchScope !== null) {
					$intersectedScope = $branchScopeWithInitialScopeRemoved->addVariables($previousBranchScope);
					$allBranchesScope = $allBranchesScope->addVariables($previousBranchScope);
				} else {
					$intersectedScope = $intersectedScope->intersectVariables($branchScopeWithInitialScopeRemoved);
					$allBranchesScope = $allBranchesScope->addVariables($branchScopeWithInitialScopeRemoved);
				}
			}

			if ($earlyTerminationStatement === null) {
				$previousBranchScope = $branchScopeWithInitialScopeRemoved;
			} else {
				$previousBranchScope = null;
			}
		}

		if ($intersectedScope !== null && $allBranchesScope !== null) {
			$allBranchesScope = $allBranchesScope->removeVariables($intersectedScope, true);
			$allBranchesScope = $allBranchesScope->intersectVariables($initialScope);
			$scope = $initialScope
				->mergeWithIntersectedScope($intersectedScope)
				->mergeWithIntersectedScope($allBranchesScope);

			return $scope;
		}

		return $initialScope;
	}

	/**
	 * @param \PhpParser\Node[] $statements
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return \PhpParser\Node|null
	 */
	private function findEarlyTermination(array $statements, Scope $scope)
	{
		foreach ($statements as $statement) {
			$statement = $this->findStatementEarlyTermination($statement, $scope);
			if ($statement !== null) {
				return $statement;
			}
		}

		return null;
	}

	/**
	 * @param \PhpParser\Node $statement
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return \PhpParser\Node|null
	 */
	private function findStatementEarlyTermination(Node $statement, Scope $scope)
	{
		if (
			$statement instanceof Throw_
			|| $statement instanceof Return_
			|| $statement instanceof Continue_
			|| $statement instanceof Break_
			|| $statement instanceof Exit_
		) {
			return $statement;
		} elseif ($statement instanceof MethodCall && count($this->earlyTerminatingMethodCalls) > 0) {
			if (!is_string($statement->name)) {
				return null;
			}

			$methodCalledOnType = $scope->getType($statement->var);
			if ($methodCalledOnType->getClass() === null) {
				return null;
			}

			if (!$this->broker->hasClass($methodCalledOnType->getClass())) {
				return null;
			}

			$classReflection = $this->broker->getClass($methodCalledOnType->getClass());
			foreach (array_merge([$methodCalledOnType->getClass()], $classReflection->getParentClassesNames()) as $className) {
				if (!isset($this->earlyTerminatingMethodCalls[$className])) {
					continue;
				}

				if (in_array($statement->name, $this->earlyTerminatingMethodCalls[$className], true)) {
					return $statement;
				}
			}

			return null;
		} elseif ($statement instanceof If_) {
			if ($statement->else === null) {
				return null;
			}

			if (!$this->findEarlyTermination($statement->stmts, $scope)) {
				return null;
			}

			foreach ($statement->elseifs as $elseIfStatement) {
				if (!$this->findEarlyTermination($elseIfStatement->stmts, $scope)) {
					return null;
				}
			}

			if (!$this->findEarlyTermination($statement->else->stmts, $scope)) {
				return null;
			}

			return $statement;
		}

		return null;
	}

	/**
	 * @param \PhpParser\Node\Expr $functionCall
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return null|\PHPStan\Reflection\ParametersAcceptor
	 */
	private function findParametersAcceptorInFunctionCall(Expr $functionCall, Scope $scope)
	{
		if ($functionCall instanceof FuncCall && $functionCall->name instanceof Name) {
			if ($this->broker->hasFunction($functionCall->name, $scope)) {
				return $this->broker->getFunction($functionCall->name, $scope);
			}
		} elseif ($functionCall instanceof MethodCall && is_string($functionCall->name)) {
			$type = $scope->getType($functionCall->var);
			if ($type->getClass() !== null && $this->broker->hasClass($type->getClass())) {
				$classReflection = $this->broker->getClass($type->getClass());
				$methodName = $functionCall->name;
				if ($classReflection->hasMethod($methodName)) {
					return $classReflection->getMethod($methodName, $scope);
				}
			}
		} elseif (
			$functionCall instanceof Expr\StaticCall
			&& $functionCall->class instanceof Name
			&& is_string($functionCall->name)) {
			$className = (string) $functionCall->class;
			if ($this->broker->hasClass($className)) {
				$classReflection = $this->broker->getClass($className);
				if ($classReflection->hasMethod($functionCall->name)) {
					return $classReflection->getMethod($functionCall->name, $scope);
				}
			}
		}

		return null;
	}

	private function processTraitUse(Node\Stmt\TraitUse $node, Scope $classScope, \Closure $nodeCallback)
	{
		foreach ($node->traits as $trait) {
			$traitName = (string) $trait;
			if (!$this->broker->hasClass($traitName)) {
				continue;
			}
			$traitReflection = $this->broker->getClass($traitName);
			$fileName = $this->fileHelper->normalizePath($traitReflection->getNativeReflection()->getFileName());
			if ($this->fileExcluder->isExcludedFromAnalysing($fileName)) {
				return;
			}
			if (!isset($this->analysedFiles[$fileName])) {
				return;
			}
			$parserNodes = $this->parser->parseFile($fileName);
			$className = sprintf('class %s', $classScope->getClassReflection()->getDisplayName());
			if ($classScope->getClassReflection()->getNativeReflection()->isAnonymous()) {
				$className = 'anonymous class';
			}
			$classScope = $classScope->changeAnalysedContextFile(
				sprintf(
					'%s (in context of %s)',
					$fileName,
					$className
				)
			);

			$this->processNodesForTraitUse($parserNodes, $traitName, $classScope, $nodeCallback);
		}
	}

	/**
	 * @param \PhpParser\Node[]|\PhpParser\Node $node
	 * @param string $traitName
	 * @param \PHPStan\Analyser\Scope $classScope
	 * @param \Closure $nodeCallback
	 */
	private function processNodesForTraitUse($node, string $traitName, Scope $classScope, \Closure $nodeCallback)
	{
		if ($node instanceof Node) {
			if ($node instanceof Node\Stmt\Trait_ && $traitName === (string) $node->namespacedName) {
				$this->processNodes($node->stmts, $classScope->enterFirstLevelStatements(), $nodeCallback);
				return;
			}
			if ($node instanceof Node\Stmt\ClassLike) {
				return;
			}
			foreach ($node->getSubNodeNames() as $subNodeName) {
				$subNode = $node->{$subNodeName};
				$this->processNodesForTraitUse($subNode, $traitName, $classScope, $nodeCallback);
			}
		} elseif (is_array($node)) {
			foreach ($node as $subNode) {
				$this->processNodesForTraitUse($subNode, $traitName, $classScope, $nodeCallback);
			}
		}
	}

	private function enterClassMethod(Scope $scope, Node\Stmt\ClassMethod $classMethod): Scope
	{
		list($phpDocParameterTypes, $phpDocReturnType) = $this->getPhpDocs($scope, $classMethod);

		return $scope->enterClassMethod(
			$classMethod,
			$phpDocParameterTypes,
			$phpDocReturnType
		);
	}

	private function getPhpDocs(Scope $scope, Node\FunctionLike $functionLike): array
	{
		$phpDocParameterTypes = [];
		$phpDocReturnType = null;
		if ($functionLike->getDocComment() !== null) {
			$docComment = $functionLike->getDocComment()->getText();
			$file = $scope->getFile();
			if ($functionLike instanceof Node\Stmt\ClassMethod) {
				$phpDocBlock = PhpDocBlock::resolvePhpDocBlockForMethod(
					$this->broker,
					$docComment,
					$scope->getClassReflection()->getName(),
					$functionLike->name,
					$file
				);
				$docComment = $phpDocBlock->getDocComment();
				$file = $phpDocBlock->getFile();
			}
			$fileTypeMap = $this->fileTypeMapper->getTypeMap($file);
			$phpDocParameterTypes = TypehintHelper::getParameterTypesFromPhpDoc(
				$fileTypeMap,
				array_map(function (Param $parameter): string {
					return $parameter->name;
				}, $functionLike->getParams()),
				$docComment
			);
			$phpDocReturnType = TypehintHelper::getReturnTypeFromPhpDoc($fileTypeMap, $docComment);
		}

		return [$phpDocParameterTypes, $phpDocReturnType];
	}

	private function enterFunction(Scope $scope, Node\Stmt\Function_ $function): Scope
	{
		list($phpDocParameterTypes, $phpDocReturnType) = $this->getPhpDocs($scope, $function);

		return $scope->enterFunction(
			$function,
			$phpDocParameterTypes,
			$phpDocReturnType
		);
	}

}
