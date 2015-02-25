<?php

class PhpShell_Input extends PhpShell_Entity
{
	protected static $_relations = [
		'user' => PhpShell_User,
		'source' => PhpShell_Input,
	];
	protected static $_numerical = ['operationCount', 'run', 'penalty'];

	protected static $_exitCodes = array(
		139 => 'Segmentation Fault',
		137 => 'Process was killed',
		255 => 'Generic Error',
	);
	const PATH  = '/var/lxc/php_shell/in/';
	const VLD_MATCH = '~ *(?<line>\d*) *\d+[ >]+(?<op>[A-Z_]+) *(?<ext>[0-9A-F]*) *(?<return>[0-9:$]*)\s+(\'(?<operand>.*)\')?~';

	public function getCode()
	{
		if (!is_readable(self::PATH. $this->short))
			throw new PhpShell_Input_NoSourceException('Although we have heard of this script; we are not sure where we left the sourcecode...');

		return file_get_contents(self::PATH. $this->short);
	}

	public static function clean($code)
	{
		return trim(str_replace(array("\r\n", "\r", "\xE2\x80\x8B"), array("\n", "\n", ""), $code));
	}

	public static function getHash($code)
	{
		return gmp_strval(gmp_init(sha1($code), 16), 58);
	}

	public static function byHash($hash)
	{
		return self::find('hash = ?', [$hash])->getSingle();
	}

	public static function create($code, PhpShell_Input $source = null)
	{
		if (false !== strpos($code, 'pcntl_fork(') || false !== strpos($code, ':|:&') || false !== strpos($code, ':|: &'))
			throw new PhpShell_Input_GoFuckYourselfException('You must be really proud of yourself, trying to break a free service');

		$hash = self::getHash($code);
		$len = 5;

		do
		{
			$short = substr($hash, -$len);
			$dups = self::find('short = ?', [$short]);

			$len++;
		}
		while (count($dups) > 0);

		if (file_exists(self::PATH. $short))
			throw new PhpShell_Input_DuplicateScriptException('Duplicate script, this shouldn\'t happen');

		umask(0022);
		file_put_contents(self::PATH. $short, $code);

		$extra = isset(Basic::$action->user) ? ['user' => Basic::$action->user] : [];
		$input = parent::create(['short' => $short, 'source' => $source, 'hash' => $hash] + $extra);

		$input->trigger();

		return $input;
	}

	public function updateOperations()
	{
		try
		{
			$vld = $this->getVld()->getSingle();
		}
		catch (Basic_EntitySet_NoSingleResultException $e)
		{
			return;
		}

		preg_match_all(self::VLD_MATCH, $vld->output->getRaw($this, 'vld'), $operations, PREG_SET_ORDER);

		$this->save(['operationCount' => count($operations)]);

		foreach ($operations as $match)
		{
			PhpShell_Operation::create([
				'input' => $this,
				'operation' => $match['op'],
				'operand' => isset($match['operand']) ? $match['operand'] : null,
			]);
		}
	}

	public function trigger()
	{
		if (0 == Basic::$database->query("SELECT COUNT(*) c FROM queue WHERE input = ?", [$this->short])->fetchArray('c')[0])
			Basic::$database->query("INSERT INTO queue VALUES (?, null)", [$this->short]);

		// Make sure state comes fresh from the db
		$this->removeCached();

		usleep(200 * 1000);
	}

	public function getOutput()
	{
		$results = new PhpShell_MainScriptOutput(PhpShell_Result, 'input = ? AND result.run = ? AND NOT version."isHelper"', array($this->id, $this->run), ['version.order' => true]);

		$abbrMax = function($name)
		{
			return str_replace(['hhvm-', 'php7@'], '', $name);
		};

		$outputs = array();
		foreach ($results as $result)
		{
			$output = $result->output->getRaw($result->input, $result->version->name);

			$hash = sha1($output.':'.$result->exitCode);
			$slot =& $outputs[ $hash ];

			$isHhvm = (false !== strpos($result->version->name, 'hhvm-'));
			$isNg = (false !== strpos($result->version->name, '@201'));

			$result->version->name = '<span title="released '. $result->version->released. '">'.$result->version->name.'</span>';

			if (!isset($slot))
				$slot = array('min' => $result->version->name, 'versions' => [], 'order' => 0);
			elseif ($hash != $prevHash || ($isNg && !$prevNg) || ($isHhvm && !$prevHhvm))
			{
				// Close previous slot
				if (isset($slot['max']))
					array_push($slot['versions'], $slot['min'] .' - '. $abbrMax($slot['max']));
				elseif (isset($slot['min']))
					array_push($slot['versions'], $slot['min']);

				$slot['min'] = $result->version->name;
				unset($slot['max']);
			}
			elseif (!isset($slot['min']))
				$slot['min'] = $result->version->name;
			else
				$slot['max'] = $result->version->name;

			$slot['order'] = max($slot['order'], $result->version->order);
			$slot['output'] = htmlspecialchars($output, ENT_SUBSTITUTE);

			if ($result->exitCode > 0)
			{
				$title = isset(self::$_exitCodes[ $result->exitCode ]) ? ' title="'. self::$_exitCodes[ $result->exitCode ] .'"' : '';
				$slot['output'] .= '<br/><i>Process exited with code <b'. $title .'>'. $result->exitCode .'</b>.</i>';
			}

			$prevHash = $hash;
			$prevHhvm = $isHhvm;
			$prevNg = $isNg;
		}

		usort($outputs, function($a, $b){ return $b['order'] - $a['order']; });

		$versions = array();
		foreach ($outputs as $output)
		{
			// Process unclosed slots
			if (isset($output['max']))
				array_push($output['versions'], $output['min'] .' - '. $abbrMax($output['max']));
			elseif (isset($output['min']))
				array_push($output['versions'], $output['min']);

			$versions[ implode(', ', $output['versions']) ] = $output['output'];
		}

		return $versions;
	}

	public function getPerf()
	{
		return Basic::$database->query("
			SELECT
				ROUND(AVG(\"systemTime\")::numeric, 3) as system,
				ROUND(AVG(\"userTime\")::numeric, 3) as user,
				ROUND(AVG(\"maxMemory\")/1024, 2) as memory,
				MAX(version.name) as version,
				SUM(result.\"exitCode\") as exit_sum
			FROM result
			INNER JOIN version ON version.id = result.version
			WHERE result.input = ? AND NOT version.\"isHelper\"
			GROUP BY result.version
			ORDER BY MAX(version.order) DESC", [$this->id]);
	}

	public function getRefs()
	{
		return Basic::$database->query("
			WITH RECURSIVE recRefs(id, operation, link, name, parent) AS (
			  SELECT id, r.operation, link, name, parent
			  FROM operations o
			  INNER JOIN \"references\" r ON r.operation = o.operation AND (o.operand = r.operand OR r.operand IS NULL)
			  WHERE input = ?
			  UNION ALL
			  SELECT C.id, C.operation, C.link, C.name, C.parent
			  FROM recRefs P
			  INNER JOIN \"references\" C on P.id = C.parent
			)
			SELECT link, name FROM recRefs;", [$this->id]);
	}

	public function getLastModified()
	{
		return Basic::$database->query("SELECT MAX(created) max FROM result WHERE input = ? AND run = ?", [$this->id, $this->run])->fetchArray('max')[0];
	}

	public function getSegfault()
	{
		return PhpShell_Result::find('input = ? AND version = ? AND run = ? AND "exitCode" = 139', [
			$this->id,
			PhpShell_Version::byName('segfault'),
			$this->run,
		]);
	}

	public function getVld()
	{
		$emptyOutput = Basic::$cache->get(__CLASS__.'::vldEmpty', function(){
			return PhpShell_Output::find("hash = ?", [base64_encode(sha1('', true))])->getSingle();
		});

		return PhpShell_Result::find('input = ? AND version = ? AND run = ? AND output != ?', [
			$this->id,
			PhpShell_Version::byName('vld'),
			$this->run,
			$emptyOutput,
		]);
	}

	public function getLastVersionResult($version)
	{
		return PhpShell_Result::find('input = ? AND version = ? AND run = ?', [
			$this->id,
			PhpShell_Version::byName($version),
			$this->run,
		]);
	}

	public function getBytecode()
	{
		return PhpShell_Result::find('input = ? AND version = ? AND run = ?', [
			$this->id,
			PhpShell_Version::byName('hhvm-bytecode'),
			$this->run,
		]);
	}

	public function getAnalyze()
	{
		$emptyOutput = Basic::$cache->get(__CLASS__.'::analyzeEmpty', function(){
			return PhpShell_Output::find("hash = ?", [base64_encode(sha1('[]', true))])->getSingle();
		});

		return PhpShell_Result::find('input = ? AND version = ? AND run = ? AND "exitCode" = 0 AND output != ?', [
			$this->id,
			PhpShell_Version::byName('hhvm-analyze'),
			$this->run,
			$emptyOutput->id,
		]);
	}

	protected function _checkPermissions($action)
	{
		if ($action == 'save' && $this->title !== $this->_dbData->title && $this->user->id !== Basic::$action->user->id)
			throw new PhpShell_Input_TitleChangeNotAllowedException('Permission denied, only the owner can update the title', [], 403);

		return true;
	}
}
