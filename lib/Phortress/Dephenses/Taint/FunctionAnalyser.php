<?php
namespace Phortress\Dephenses\Taint;

use Phortress\Exception\UnboundIdentifierException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

class FunctionAnalyser{

    /**
     * Return statements and the variables they are dependent on.
     * array(Stmt => array(variable name => VariableInfo)
     */
    protected $returnStmts = array();
    
    /**
     * The parameters to the function
     */
    protected $params = array();
    
    /**
     * The statements in the function
     */
    protected $functionStmts;
    
    /**
     * array(variable name => VariableInfo)
     */
    protected $variables = array();


	protected $unresolved_variables = array();
	
    /**
     * Environment where the function was defined
     */
    protected $environment;
    
    protected $function;
    
    public function __construct(\Phortress\Environment $env, $functionName) {
        assert(!($functionName instanceof Expr));
        //For now we do not handle dynamic function names;
        $this->environment = $env;
        $this->function = $env->resolveFunction($functionName);
        $this->functionStmts = $this->function->stmts;
        $this->params = $this->function->params;
        $this->analyseReturnStatementsDependencies($this->functionStmts);
    }
    
    public static function getFunctionAnalyser(\Phortress\Environment $env, $functionName){
        assert(!empty($env));
        $func_def = $env->resolveFunction($functionName);
        $analyser = $func_def->analyser;
        if(empty($analyser)){
            $analyser = new FunctionAnalyser($env, $functionName);
            $func_def->analyser = $analyser;
        }
        return $analyser;
    }
    
    /**
     * Takes in an array of Node\Args[]
     * Returns an array containing taint value of the value returned by the function,
     * and the array of sanitising functions applied
     */
    public function analyseFunctionCall($args){
        $result = array(Annotation::UNASSIGNED, array());
        foreach($this->returnStmts as $line_num => $return){
            $ret_effect = $this->analyseArgumentsEffectOnReturn($args, $return);
            $result = array(max($result[0], $ret_effect[0]), array_merge($result[1], $ret_effect[1]));
        }
        return $result;
    }

    private function analyseArgumentsEffectOnReturn($args, $return){
        $taint_mappings = $this->getParametersToTaintMappings($args);
        $sanitising_funcs = array();
        $taint_val = Annotation::UNASSIGNED;
        foreach($return as $var_name => $var_info){

	        $sanitising_funcs = array_merge($sanitising_funcs,
			        $var_info->getSanitisingFunctions());


            if($var_info->getTaint()){
                $taint_val = max($taint_val, $var_info->getTaint());
            }
            if(array_key_exists($var_name, $taint_mappings)){
                $taint_val = max($taint_val, $taint_mappings[$var_name]);
            }
        }
        return array($taint_val, $sanitising_funcs);
    }
    
    private function getParametersToTaintMappings($args){
        $mappings = array();
        for($i = 0; $i<count($args);$i++){
            $param = $this->params[$i];
            $arg_val = $args[$i]->value;
            $taint_val = StmtAnalyser::resolveExprTaint($arg_val);
            $mappings[$param->name] = $taint_val;
        }
        return $mappings;
    }

    private function analyseReturnStatementsDependencies($stmts){
        $retStmts = $this->getReturnStatements($stmts);
        $stmt_dependencies = array();
        foreach($retStmts as $ret){
            $depending_vars = $this->analyseStatementDependency($ret);
            $index = $ret->getLine(); //Use the statement's line number to index the statement for now.
            $stmt_dependencies[$index] = $depending_vars;
        }
        $this->returnStmts = $stmt_dependencies;
    }
    
    private function analyseStatementDependency(Stmt\Return_ $stmt){
        $exp = $stmt->expr;
        $trace = array();
        if($exp instanceof Expr){
            $trace = $this->traceExpressionVariables($exp);
        }
        return $trace;
    }
    
    private function traceExpressionVariables(Expr $exp){
        if($exp instanceof Node\Scalar){
	        return array();
        }else if ($exp instanceof Expr\Variable) {
            return $this->traceVariable($exp);
        }else if(($exp instanceof Expr\ClassConstFetch) || ($exp instanceof Expr\ConstFetch)){
	        return array();
        }else if($exp instanceof Expr\PreInc || $exp instanceof Expr\PreDec || $exp instanceof Expr\PostInc || $exp instanceof Expr\PostDec){
            $var = $exp->var;
            return $this->traceVariable($var);
        }else if($exp instanceof Expr\BinaryOp){
            return $this->traceBinaryOp($exp);
        }else if($exp instanceof Expr\UnaryMinus || $exp instanceof Expr\UnaryPlus){
            $var = $exp->expr;
            return $this->traceVariable($var);
        }else if($exp instanceof Expr\Array_){
            return $this->traceVariablesInArray($exp);
        }else if($exp instanceof Expr\ArrayDimFetch){
            //For now treat all array dimension fields as one
            $var = $exp->var;
            return $this->traceVariable($var);
        }else if($exp instanceof Expr\PropertyFetch){
            $var = $exp->var;
            return $this->traceVariable($var);
        }else if($exp instanceof Expr\StaticPropertyFetch){
            //TODO:
        }else if($exp instanceof Expr\FuncCall){
            return $this->traceFunctionCall($exp);
        }else if($exp instanceof Expr\MethodCall){
            return $this->traceMethodCall($exp);
        }else if($exp instanceof Expr\Ternary){
            //If-else block
           return $this->traceTernaryTrace($exp);
        }else if($exp instanceof Expr\Eval_){
            return $this->resolveTernaryTrace($exp->expr);
        }else{
            //Other expressions we will not handle.
	        return array();
        }
    }
    
    private  function traceFunctionCall(Expr\FuncCall $exp){
        $func_name = $exp->name;
        $args = $exp->args;
        $traced_args = array();
        
        foreach($args as $arg){
            $arg_val = $arg->value;
            $traced = $this->traceExpressionVariables($arg_val);
            
            $traced_args[] = $this->addSanitisingFunctionInfo($traced, $func_name);
        }
        return VariableInfo::mergeVariables($traced_args);
    }
    
    private function addSanitisingFunctionInfo($var_infolist, $func_name){
        foreach($var_infolist as $var=>$infolist){
            $original = $infolist[self::SANITISATION_KEY];
            if(\SanitisingFunctions::isSanitisingFunction($func_name)){
                $new_list = array_merge($original, array($func_name));
                $infolist[self::SANITISATION_KEY] = $new_list;
            }else if(\SanitisingFunctions::isSanitisingReverseFunction($func_name)){
                $reverse_func = \SanitisingFunctions::getAffectedSanitiser($func_name);
                $new_list = array_diff($original, array($reverse_func));
                $infolist[self::SANITISATION_KEY] = $new_list;
            }
        }
        return $var_infolist;
    }
    
    private  function traceMethodCall(Expr\MethodCall $exp){
        
    }
    
     private function resolveTernaryTrace(Expr\Ternary $exp){
	     //TODO:
        $if = $exp->if;
        $else = $exp->else;
        $if_trace = $this->traceExpressionVariables($if);
        $else_trace = $this->traceExpressionVariables($else);
        return TaintInfo::mergeTaintValues($if_trace, $else_trace);
    }
    
    private function traceVariablesInArray(Expr\Array_ $arr){
        $arr_items = $arr->items;
        $var_traces = array();
        foreach($arr_items as $item){
            $exp = $item->value;
            $var_traces[] = $this->traceExpressionVariables($exp);
        }
        return TaintInfo::mergeVariables($var_traces);
    }
    
    private function traceBinaryOp(Expr\BinaryOp $exp){
        $left = $exp->left;
        $right = $exp->right;
        $left_var = $this->traceExpressionVariables($left);
        $right_var = $this->traceExpressionVariables($right);
        return VariableInfo::mergeVariables(array_merge($left_var, $right_var));
    }

    private function traceVariable(Expr\Variable $var){
        $name = $var->name;
        if($name instanceof Expr){
	        //TODO: fix case where variable cannot be resolved statically
        }else{
	        $var_details = $this->getVariableDetails($var);
	        $details_ret = array($name => $var_details);

	        if(InputSources::isInputVariable($var)){
		        $var_details->setTaint(Annotation::TAINTED);
		        return $details_ret;
	        }

	        if(!$this->isFunctionParameter($var)){
		        $assign = $var_details->getDefinition();
		        if(!empty($assign)){
			        $ref_expr = $assign->expr;
			        return $this->traceExpressionVariables($ref_expr);
		        }else{
			        return $details_ret;
		        }

	        }else{
		        return $details_ret;
	        }
        }

    }
    
    private function isFunctionParameter(Expr\Variable $var){
        $name = $var->name;
        $filter = function($item) use ($name){
            return ($item->name == $name);
        };
        $matches = array_filter($this->params, $filter);
        return !empty($matches);
    }
    
    private function getVariableDetails(Expr\Variable $var){
        $name = $var->name;
        if(array_key_exists($name, $this->variables)){
            return $this->variables[$name];
        }else if($name instanceof Expr){
	        //TODO: fix case where variable cannot be statically resolved
//            $unresolved_vars = $this->variables[self::UNRESOLVED_VARIABLE_KEY];
//            $filter_matching = function($item) use ($var){
//                return $item->getVariable() == $var;
//            };
//            $filter_res = array_filter($unresolved_vars, $filter_matching);
//            if(!empty($filter_res)){
//                return $filter_res;
//            }else{
//	            //TODO:
//                $varInfo = new VariableInfo($var, Annotation::UNKNOWN);
//                $unresolved_vars[] = $varInfo;
//                return $varInfo;
//            }
        }else{
            if(array_key_exists($name, $this->variables)){
                return $this->variables[$name];
            }else{
	            try{
		            $assign = $var->environment->resolveVariable($name);
	            }catch(UnboundIdentifierException $e){
					print_r($name);
		            var_dump("error happened");
	            }
//	            $assign = $var->environment->resolveVariable($name);
                $varInfo = new VariableInfo($var);
	            if(!empty($assign)){
		            $varInfo->setDefinition($assign);
	            }

                $this->variables[$name] = $varInfo;
                return $varInfo;
            }
        }
    }
    
    private function getReturnStatements($stmts){
        $filter_returns = function($item){
            return ($item instanceof Stmt\Return_);
        };
        return array_filter($stmts, $filter_returns);
    }
    
}
