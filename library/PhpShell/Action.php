<?php

class PhpShell_Action extends Basic_Action
{
	public $encoding = 'UTF-8';
	public $title = 'Run code in 200+ PHP & HHVM versions';
	public $user;
	public $bodyClass;
	public $adminMessage;

	public function init()
	{
		if (isset($_SESSION['userId']))
			$this->user = PhpShell_User::get($_SESSION['userId']);
		elseif (!empty($_COOKIE))
		{
			foreach (array_keys($_COOKIE) as $name)
				setcookie($name, '', strtotime('-1 day'));
		}

		header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
		header('X-Frame-Options: DENY');
		header('X-Xss-Protection: 1; mode=block');
		header('X-Content-Type-Options: nosniff');
		$cspDirectives = [
			'script-src' => [
				"'self'",
				'cdn.jsdelivr.net',
			],
			'child-src' => [ # valid sources for web-workers
				"'self'",
				'cdn.jsdelivr.net',
			],
			'connect-src' => [
				"'self'", # for xhr
			],
			'img-src' => ["'self'", 'data:',],
			'style-src' => [
				"'self'",
				"'unsafe-inline'", # for ace-editor & tagcloud
			]
		];

		$csp = "default-src 'none'; ";
		foreach ($cspDirectives as $directive => $settings)
			$csp .= $directive .' '.implode(' ', $settings). '; ';

		header('Content-Security-Policy: '. $csp .'report-uri https://3v4l.report-uri.io/r/default/csp/enforce');

		if (0 && $_GET['waa']=='meukee')
		{
			$wasOn = Basic::$config->PRODUCTION_MODE;
			Basic::$config->PRODUCTION_MODE = false;

			if ($wasOn)
				Basic::$log->start(get_class(Basic::$action) .'::init');
		}

		if ($_GET['resetOpcache'] == sha1_file(APPLICATION_PATH .'/htdocs/index.php'))
			die(print_r(opcache_get_status(false)+['RESET' => opcache_reset()]));

		if ('application/json' == $_SERVER['HTTP_ACCEPT'])
			$this->contentType = 'application/json';
		elseif ('text/plain' == $_SERVER['HTTP_ACCEPT'])
			$this->contentType = 'text/plain';

		// Since we resolve everything to 'script'; prevent random strings in bodyClass
		if (! Basic::$action instanceof PhpShell_Action_Script)
			$this->bodyClass = trim($this->bodyClass .' '.Basic::$userinput['action']);

		try
		{
			$this->adminMessage = Basic::$cache->get('banMessage::'. $_SERVER['REMOTE_ADDR']);
		}
		catch (Basic_Memcache_ItemNotFoundException $e)
		{
			#care
		}

		try
		{
			$this->adminMessage = Basic::$cache->get('adminMessage::'. $_SERVER['REMOTE_ADDR']);
			Basic::$cache->delete('adminMessage::'. $_SERVER['REMOTE_ADDR']);
		}
		catch (Basic_Memcache_ItemNotFoundException $e)
		{
			#care
		}

		if (isset($this->adminMessage))
		{
			header('X-Accel-Expires: 0');
			$this->_cacheLength = 0;
		}

		parent::init();
	}

	protected function _handleLastModified()
	{
		if (isset($_SESSION['userId']) || 'text/html' != $this->contentType)
			$this->_cacheLength = 0;

		parent::_handleLastModified();
	}

	public static function resolve($action, $hasClass, $hasTemplate)
	{
		if ($hasClass)
			return;

		return 'script';
	}
}