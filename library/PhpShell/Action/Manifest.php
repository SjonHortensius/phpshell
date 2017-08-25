<?php
// Serve /manifest as json
class PhpShell_Action_Manifest extends PhpShell_Action
{
	public $contentType = 'application/json';
	protected $_cacheLength = '7 days';

	protected function _handleLastModified()
	{
		// Skip PhpShell_Action that specifies cacheLength=0 for non-html contentTypes
		return Basic_Action::_handleLastModified();
	}
}