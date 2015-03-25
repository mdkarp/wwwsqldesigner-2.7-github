<?php
session_start();
$config = require_once 'config/config.php';
$a = (isset($_GET["action"]) ? $_GET["action"] : false);
switch ($a) {
    case "list":
        $path = $_SESSION['github_path'];
        try {
            $apiResponse = githubGetContents($config, $path);
            if ($apiResponse['code'] != '200') {
                header("HTTP/1.0 " . $apiResponse['code']);
                break;
            }
        
            $bodyResponse = json_decode($apiResponse['body']);
            if (is_array($bodyResponse)) {
                foreach ($bodyResponse as $content) {
                    $info = pathinfo($content->name);
                    if ($content->type == 'file' && $info['extension'] == substr($config['schema_ext'], 1))
                        echo basename($content->name, $config['schema_ext']), PHP_EOL;
                }
            }
        } catch (\Exception $e) {
            header("HTTP/1.0 500 Internal Server Error");
            break;
        }
         
        break;
    case "load":
        // retrieve file from github
        $keyword = isset($_GET["keyword"]) ? $_GET["keyword"] . $config['schema_ext'] : "";
        $path = !empty($_SESSION['github_path']) ? $_SESSION['github_path'] . '/' . $keyword : $keyword;
        try {
            $apiResponse = githubGetContents($config, $path);
            if ($apiResponse['code'] != '200') {
                header("HTTP/1.0 " . $apiResponse['code']);
                break;
	        }
	         
            $bodyResponse = json_decode($apiResponse['body']);
            $content = base64_decode($bodyResponse->content);
        } catch (\Exception $e) {
            header("HTTP/1.0 500 Internal Server Error");
            break;
        }
	    
        header("Content-type: text/xml");
        echo $content;
        break;
    case "save":
        if (! isset($_SESSION['oauth'])) {
            // if there is no API access token, authorize the application first
            $requestUri = $_SERVER['REQUEST_URI'];
            $resource   = substr($requestUri, 0, strpos($requestUri, $_SERVER['QUERY_STRING']));
            $authorizeQuery = 'action=request_authorization';
            header('location: ' . $resource . $authorizeQuery);
        }
        
        $keyword = isset($_GET["keyword"]) ? $_GET["keyword"] . $config['schema_ext'] : "";
        $path = !empty($_SESSION['github_path']) ? $_SESSION['github_path'] . '/' . $keyword : $keyword;
        // get file to get sha1 hash. To define this request for update or create a file in GitHub
        try {
            $apiResponse = githubGetContents($config, $path);
            if ($apiResponse['code'] == '200') {
                $bodyResponse = json_decode($apiResponse['body']);
                $content = base64_decode($bodyResponse->content);
                $sha = $bodyResponse->sha;
            }
        } catch (\Exception $e) {
            header("HTTP/1.0 500 Error when checking file in GitHub");
            exit;
        }
        
        // /repos/:owner/:repo/contents/:path
        $apiUri  = $config['github_api_host'] . '/repos/' . $_SESSION['github_repo_owner']
                 . '/' . $_SESSION['github_repo_name'] . '/contents'
                 . '/' . $path;
        $httpHeaders = array('Accept: application/vnd.github.beta+json',
                             'Authorization: token ' . $_SESSION['oauth']->access_token
                            );
        $data = file_get_contents("php://input");
        try {
            $httpBody = array('path' => $_SESSION['github_path'] . '/' . $keyword,
                              'content' => base64_encode($data)
                             );
            // define message for adding or updating
            if (isset($sha)) {
                $httpBody['message'] = 'Updated by SQLDesigner';
                $httpBody['sha'] = $sha; 
            } else {
                $httpBody['message'] = 'Added by SQLDesigner';
            }
            
            $putResponse = request($apiUri, 'PUT', json_encode($httpBody), $httpHeaders);
            if ($putResponse['code'] != '201' && $putResponse['code'] != '200') {
                header("HTTP/1.0 " . $putResponse['code']);
                break;
            }
             
            $bodyResponse = json_decode($putResponse['body']);
        } catch (\Exception $e) {
            header("HTTP/1.0 500 Internal Server Error");
            break;
        }
        
        header("HTTP/1.0 201 Created");
        break;
    case "request_authorization":
        if (isset($_POST['submit'])) {
            $_SESSION['github_repo_owner'] = $_POST['github_repo_owner'];
            $_SESSION['github_repo_name']  = $_POST['github_repo_name'];
            $_SESSION['github_path']  = $_POST['github_path'];
            // set uri to request authorization
            $uri = $config['get_authorize_uri'] . '?client_id=' . urlencode($config['client_id'])
                 . '&scope=' . urlencode($config['scope'])
                 . '&redirect_uri=' . urlencode($config['callback_uri']);
            header('location: ' . $uri);
            exit;
        }
        
        // display form
        echo '
        <html xmlns="http://www.w3.org/1999/xhtml">
        <head>
        <title>WWW SQL Designer</title>
        <meta name="viewport" content="initial-scale=1,maximum-scale=1" />
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <link rel="stylesheet" type="text/css" href="../../styles/style.css" media="all" />
        <!--[if IE 6]><link rel="stylesheet" type="text/css" href="../../styles/ie6.css" /><![endif]-->
        <!--[if IE 7]><link rel="stylesheet" type="text/css" href="../../styles/ie7.css" /><![endif]-->
        <link rel="stylesheet" href="../../styles/print.css" type="text/css" media="print" />
        </head>
        <body>
                
          <div id="window" style="left: 270px; top: 52px; visibility: visible;">
            <form method="post">
			<div id="windowtitle"><img id="throbber" src="../../images/throbber.gif" alt="Please wait..." title="Please wait..." style="visibility: hidden;">GitHub Configuration</div>
			<div id="windowcontent">
              <div id="io">
		        <table>
		 	      <tbody>
                    <tr>
					  <td colspan="2">
							<label id="output">Repository Owner</label>
                            <input type="text" size=30 name="github_repo_owner" value="' . $_SESSION['github_repo_owner'] . '" />
                            <br />
                            <label id="output">Repository Name</label>
                            <input type="text" size=30 name="github_repo_name" value="' . $_SESSION['github_repo_name'] . '" />
                            <br />
                            <label id="output">Folder (Optional)</label>
                            <input type="text" size=30 name="github_path" value="' . $_SESSION['github_path'] . '" />
					  </td>
				    </tr>
			      </tbody>
		        </table>
	          </div>
            </div>
                
			<input type="submit" name="submit" id="windowok" value="Authorize">
			<input type="button" id="windowcancel" value="Cancel" style="visibility: hidden;">
            </form>
		  </div>
                
        </body>
        </html>';        
        break;
    case "callback":
        if (isset($_GET['code'])) {
            $httpBody = array('client_id' => urlencode($config['client_id']),
                              'client_secret' => urlencode($config['client_secret']),
                              'code' => urlencode($_GET['code'])
                             );
            $httpHeaders = array('Accept: application/json');
            $message = '';
            try {
                // request OAuth access token
                $response = request($config['post_access_token_uri'], 'POST', $httpBody, $httpHeaders);
                $oauth = json_decode($response['body']);
                $_SESSION['oauth'] = $oauth;
            } catch (\Exception $e) {
                $message = $e->getMessage();
                break;
            }
        }
        
        echo "<html>
                <head>
                  <script type='text/javascript'>
                    window.setTimeout(CloseMe, 2000);
                    function CloseMe() {
                      self.close();
                    }
                  </script>
                </head>
                <body><h1>" . $message . "</h1></body>
              </html>
             ";
        break;
    default: header("HTTP/1.0 501 Not Implemented");
}

/**
 * Send request to GitHub API
 * @param $uri
 * @param $method
 * @param $httpBody
 * @param $headers
 * @throws \Exception
 * @return string
 */
function request($uri, $method, $httpBody = null, $headers = null)
{
    $ch  = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uri);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $httpBody); //no content so no body
	curl_setopt($ch, CURLOPT_USERAGENT, 'OSSDM');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
//     curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch) > 0)
        throw new \Exception('Request Error: ' . curl_error($ch));
    
    $return = array('code' => $code,
                    'body' => $body
                   );
    curl_close($ch);
    return $return;
}

/**
 * Redirect to 2 previous directory
 */
function redirectToParent()
{
    header('location: ../../index.html');
}

/**
 * Get file/folder from GitHub
 * 
 * @param $config
 * @param $path
 * @return array
 */
function githubGetContents($config, $path)
{
    // /repos/:owner/:repo/contents/:path
    $apiUri  = $config['github_api_host'] . '/repos/' . $_SESSION['github_repo_owner']
             . '/' . $_SESSION['github_repo_name'] . '/contents'
             . '/' . $path;
    $httpHeaders = array('Accept: application/vnd.github.beta+json',
                         'Authorization: token ' . $_SESSION['oauth']->access_token
                        );
    return request($apiUri, 'GET', null, $httpHeaders);
}
