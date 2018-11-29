<?php

class PhpShell_Action_Script extends PhpShell_Action
{
	public $userinputConfig = [
		'script' => [
			'valueType' => 'scalar',
			'source' => ['superglobal' => 'REQUEST', 'key' => 0],
			'required' => true,
			/* Don't check for lengths here; /randomstring will be invalid; leading to
			 * generic 400-'unknown action' instead of 404-'unknown script' error. This is
			 * caused by us interpreting everything as a script in PhpShell_Action::resolve
			 */
//			'minLength' => 5, 'maxLength' => 6,
		],
		'tab' => [
			'source' => ['superglobal' => 'REQUEST', 'key' => 1],
			'required' => false,
			'default' => 'output',
			'values' => [
				'output' => 'Output',
				'perf' => 'Performance',
				'vld' => 'VLD opcodes',
				'refs' => 'References',
//				'rel' => 'Related',
				'segfault' => 'Segmentation fault',
				'rfc' => 'RFC branches',
			]
		],
	];
	/** @var $input PhpShell_Input */
	public $input;
	public $showTab = [];
	public $bodyClass = 'new script';
	public $quickVersionList;

	public function init(): void
	{
		$this->bodyClass .= ' '. Basic::$userinput['tab'];
		$this->title = Basic::$userinput['tab'] .' for '. Basic::$userinput['script'];

		// needed because we serve different content on the same URI, which browsers may cache
		if ('.json' == strpbrk(Basic::$userinput['script'], '.') && 'application/json' == $_SERVER['HTTP_ACCEPT'])
		{
			// Discourage public /script.json usage - they should use only Accept: for that
			Basic::$template->scriptSkipCode = true;
			Basic::$userinput->script->setValue(substr(Basic::$userinput['script'], 0, -5));
		}

		// Rebecca, April 1st
		if (in_array(Basic::$userinput['script'], ['1bYJv', 'p32ZU']))
			$this->cspDirectives['frame-src'] = ['https://www.youtube.com'];

		if (in_array(Basic::$userinput['script'], ['aV2i2', 'XD6qI']) && 'Blackboard Safeassign' === $_SERVER['HTTP_USER_AGENT'])
			die(http_response_code(429));

		parent::init();
	}

	public function run(): void
	{
		try
		{
			$this->input = PhpShell_Input::find("short = ?", [Basic::$userinput['script']])->getSingle();
		}
		catch (Basic_EntitySet_NoSingleResultException $e)
		{
			try
			{
				$this->input = PhpShell_Input::find('alias = ?', [Basic::$userinput['script']])->getSingle();
			}
			catch (Basic_EntitySet_NoSingleResultException $e)
			{
				throw new PhpShell_NotFoundException('You requested a non-existing resource', [], 404);
			}

			Basic::$controller->redirect($this->input->short. ('output' != Basic::$userinput['tab'] ? '/'. Basic::$userinput['tab'] : ''), true);
		}

		if (!in_array($this->input->state, ['busy', 'new']))
		{
			$this->_lastModified = $this->input->getLastModified();
			$this->_cacheLength = '5 minutes';
		}

		// Rerun caching logic now that we have input.lastModified
		parent::_handleLastModified();

		// Attempt to retrigger the daemon
		if ($this->input->state == 'new')
			$this->input->trigger();

		if (!isset($this->input->runQuick) && Basic::$config->PRODUCTION_MODE && mt_rand(0,9)<1)
			$this->input->updateFunctionCalls();

		$this->showTab = array_fill_keys(array_keys($this->userinputConfig['tab']['values']), true);
		$this->showTab['vld'] =		isset($this->input->operationCount) && $this->input->operationCount > 0;
		$this->showTab['segfault'] =count($this->input->getSegfault()) > 0;
		$this->showTab['refs'] =	isset($this->input->operationCount) && count($this->input->getRefs()) > 0;
		$this->showTab['rfc'] =		$this->input->hasRfcOutput();

		if (false === $this->showTab[ Basic::$userinput['tab'] ])
			throw new PhpShell_Action_Script_TabHasNoContentException("This script has no output for requested tab `%s`", [Basic::$userinput['tab']], 404);

		$this->input->logHit();

		parent::run();
	}

	public static function sortAnalyzeByLine(&$messages)
	{
		usort($messages, function($a, $b){
			return $a[1]->c1[1] - $b[1]->c1[1];
		});
	}
}