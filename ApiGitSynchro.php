<?php

class ApiGitSynchro extends ApiBase {

	function execute() {

		$request = $this->getRequest();
		$response = $request->response();

		# Protect against direct web access
		if( !array_key_exists( 'ORIGIN_URI', $GLOBALS['_SERVER'] ) )
			return true;

		# Search if the user wants to edit
		$wantWrite = false;
		if( preg_match( '#/git-receive-pack$#', $GLOBALS['_SERVER']['ORIGIN_URI'] ) || preg_match( '#\?service=git-receive-pack$#', $GLOBALS['_SERVER']['ORIGIN_URI'] ) )
		#if( preg_match( '#/git-receive-pack$#', $GLOBALS['_SERVER']['REQUEST_URI'] ) || preg_match( '#\?service=git-receive-pack$#', $GLOBALS['_SERVER']['REQUEST_URI'] ) )
			$wantWrite = true;

		if( !$wantWrite ) {
			$response->statusHeader( 200 );
			exit;
		}

		# Check if, at least, there is the Authorization header
		if( !$request->getHeader( 'Authorization' ) ) {

			$response->header( 'WWW-Authenticate: Basic' );
			$response->statusHeader( 401 );
			exit;
		}

		$authorizationHeader = base64_decode( substr( $request->getHeader( 'Authorization' ), 5 ) );
		$username = substr( $authorizationHeader, 0, strpos( $authorizationHeader, ':' ) );
		$password = substr( $authorizationHeader, strpos( $authorizationHeader, ':' )+1 );

		# Retrieve title
		$origin_uri = preg_replace( '#^/(.*)/(HEAD|info/refs|objects/info/[^/]+|objects/[0-9a-f]{2}/[0-9a-f]{38}|objects/pack/pack-[0-9a-f]{40}\.(pack|idx)|git-(receive|upload)-pack)(\?[a-z0-9=-]+)?$#', '$1', $GLOBALS['_SERVER']['ORIGIN_URI'] );
		#$origin_uri = preg_replace( '#^/(.*)/(HEAD|info/refs|objects/info/[^/]+|objects/[0-9a-f]{2}/[0-9a-f]{38}|objects/pack/pack-[0-9a-f]{40}\.(pack|idx)|git-(receive|upload)-pack)(\?[a-z0-9=-]+)?$#', '$1', $GLOBALS['_SERVER']['REQUEST_URI'] );
		$title = Title::newFromText( $origin_uri );

		# Check user
		$user = User::newFromName( $username );
		if( !$user || !$user->isLoggedIn() ) {

			$response->header( 'WWW-Authenticate: Basic' );
			$response->statusHeader( 401 );
			exit;
		}

		# Check user’s password
		if( !$user->checkPassword( $password ) ) {

			$response->header( 'WWW-Authenticate: Basic' );
			$response->statusHeader( 401 );
			exit;
		}

		# Check if this user can edit this page
		if( !$title->userCan( 'edit', $user ) ) {

			$response->statusHeader( 403 );
			exit;
		}

		# Positive header
		$response->statusHeader( 200 );
		exit;

		return true;
	}

	public function getDescription() {

		return wfMessage( 'gitsynchro-api-desc' )->escaped();
	}
}
