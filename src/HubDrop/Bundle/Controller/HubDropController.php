<?php

namespace HubDrop\Bundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Guzzle\Http\Client;
use Github\Client as GithubClient;

class HubDropController extends Controller
{
  private $repo_path = '/var/hubdrop/repos';
  private $github_org = 'hubdrop-projects';
  private $jenkins_url = 'http://hubdrop:8080';

  /**
   * This was retrieved using a github username and password with curl:
   * curl -i -u <github_username> -d '{"scopes": ["repo"]}' https://api.github.com/authorizations
   */
  private $github_application_token = 'af25172c6b5dd7e2ae29d1eb98636314588f0c28';

  /**
   * Homepage
   */
  public function homeAction()
  {
    //
    $project_name = $this->get('request')->query->get('project_name');
    if ($project_name){
      return $this->redirect($this->generateUrl('_project', array(
        'project_name' => $project_name
      )));
    }

    return $this->render('HubDropBundle:HubDrop:home.html.twig', array(
      'site_base_url' => $this->getRequest()->getHost(),
    ));
  }

  /**
   * Project View Page
   */
  public function projectAction($project_name)
  {

    $params = array();
    $params['project_ok'] = FALSE;
    $params['project_cloned'] = FALSE;
    $params['message'] = '';

    $go_mirror = $this->get('request')->query->get('mirror');

    // If local repo exists...
    if (file_exists($this->repo_path . '/' . $project_name . '.git')){
      $params['project_cloned'] = TRUE;
      $params['github_web_url'] = "http://github.com/" . $this->github_org . '/' . $project_name;
      $params['project_ok'] = TRUE;
    }
    // Else If local repo doesn't exist...
    else {
      // Look for drupal.org/project/{project_name}
      $client = new Client('http://drupal.org');
      try {
        $response = $client->get('/project/' . $project_name)->send();
        $params['project_ok'] = TRUE;

        // Mirror: GO!
        // We only want to try to mirror a project if not yet cloned and it
        // exists.
        if ($go_mirror == 'go'){
          return $this->mirrorProject($project_name);
        }

      } catch (\Guzzle\Http\Exception\BadResponseException $e) {
        $params['project_ok'] = FALSE;
      }
    }

    // Build template params
    $params['project_name'] = $project_name;
    $params['project_drupal_url'] = "http://drupal.org/project/$project_name";

    if ($params['project_ok']){
      $params['project_drupal_git'] = "http://git.drupal.org/project/$project_name.git";
    }
    return $this->render('HubDropBundle:HubDrop:project.html.twig', $params);
  }

  /**
   * Mirror a Project.
   */
  private function mirrorProject($project_name)
  {
    $stop_process = FALSE;

    // Connect to GitHub and create a Repo for $project_name
    // From https://github.com/KnpLabs/php-github-api/blob/master/doc/repos.md
    $client = new GithubClient();
    $client->authenticate($this->github_application_token, '', \Github\Client::AUTH_URL_TOKEN);

    try {
      $repo = $client->api('repo')->create($project_name, 'Mirror of drupal.org provided by hubdrop.io', 'http://drupal.org/project/' . $project_name, true, $this->github_org);
      $output = "GitHub Repo created at " . $repo['html_url'];
    }
    catch (\Github\Exception\ValidationFailedException $e) {
      // For now we assume this is just a
      // "Validation Failed: name already exists on this account
      if ($e->getCode() == 422){
        $output = '<p>Repo already exists on github: http://github.com/' . $this->github_org . '/' . $project_name . '</p>';
      }
      else {
        $output = $e->getMessage();
        $stop_process = TRUE;
      }
    }

    if (!$stop_process) {
      $output = $this->jenkins_job_build('hubdrop-jenkins-create-mirror', array('NAME' => $project_name));
    }

    return new Response($output);
    //return $this->redirect('/project/' . $project_name);
  }


  /**
   * Trigger a build for a jenkins job.
   *
   * @param $job
   *   Job name.
   */
  private function jenkins_job_build($job, $params = NULL, &$response = NULL) {
    if (!$this->jenkins_is_job_name_valid($job)) {
      return FALSE;
    }

    $data = array();
    if (is_array($params)) {
      $data = array('parameter' => array());
      foreach ($params as $name => $value) {
        $data['parameter'][] = array('name' => $name, 'value' => $value);
      }
    }

    $json = 'json=' . json_encode($data);
    $headers = array('Content-Type' => 'application/x-www-form-urlencoded');

    return $this->jenkins_request("/job/{$job}/build", $response, array(), 'POST', $json, $headers);
  }

  /**
   * Perform a request to Jenkins server and return the response.
   *
   * @param $path
   *   API path with leading slash, e.g. '/api/json'.
   * @param $query
   *   Array with data to be sent as query string.
   * @param $method
   *   HTTP method, either 'GET' (default) or 'POST'.
   * @param $data
   *   Post data.
   * @param $headers
   *   HTTP headers.
   *
   * return
   *   Object with results from the server. Or FALSE on failure.
   */
  private function jenkins_request($path, &$response = NULL, $query = array(), $method = 'GET', $data = NULL, $headers = array()) {
    $url = $this->jenkins_url. $path;
    $options = array(
      'method' => $method,
    );

    // Force request to start immediately.
    if (!isset($query['delay'])) {
      $query['delay'] = '0sec';
    }

    $url .= '?'. drupal_http_build_query($query);

    if ($method == 'POST' && !empty($data)) {
      $options['data'] = $data;
    }

    // Default to JSON unless otherwise specified.
    $default_headers = array(
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
    );
    $headers += $default_headers;

    // Do HTTP request and get response object.
    //$response = drupal_http_request($url, $options);

    // CREATE JENKINS BUILD...
    $client = new Client($url);
    try {
      $request = $client->post('/job/hubdrop-jenkins-create-mirror/build');
      $request->setHeaders($headers);

      $query = $request->getQuery();
      $query->set('token', 'hubdropHJKLHGYGYLGULHUIy678GHJKLG78HJKL');
      foreach ($query as $name => $value){
        $query->set($name, $value);
      }
      $response = $request->send();
      $output .= print_r($response, 1);
    } catch (\Guzzle\Http\Exception\BadResponseException $e) {
      $output = 'ERROR: ' . $e->getMessage();
    }

    return $output;
    // Response code should be something between 200 and 202.
    //return in_array($response->code, range(200, 202));
  }
  /**
  * Validates a jenkins job name.
  *
  * Based on Hudson.java.checkGoodName() and java's native Character.isISOControl().
  *
  * @param String $name
  *   The name of the job to validate.
  *
  * @return bool
  *   Is the name valid?
  */
 private function jenkins_is_job_name_valid($name) {
   if (preg_match('~(\\?\\*/\\\\%!@#\\$\\^&\|<>\\[\\]:;)+~', $name)) {
     return FALSE;
   }

   // Define range of non printable characters.
   $non_print_high = 31;

   // Value PHP assigns if invalid or extended ascii character (? == 63).
   $ascii_garbage = 63;

   $len = strlen($name);
   for ($i = 0; $len > $i; ++$i) {
     // Unicode char to ord logic lifted from http://stackoverflow.com/questions/1365583/how-to-get-the-character-from-unicode-value-in-php
     $char = substr($name, $i, 1);
     $unpacked = unpack('N', mb_convert_encoding($char, 'UCS-4BE', 'UTF-8'));
     $ord = $unpacked[1];

     if ($ord <= $non_print_high || $ord == $ascii_garbage) {
       return FALSE;
     }
   }

   return TRUE;
 }
}

function drupal_http_build_query(array $query, $parent = '') {
  $params = array();

  foreach ($query as $key => $value) {
    $key = ($parent ? $parent . '[' . rawurlencode($key) . ']' : rawurlencode($key));

    // Recurse into children.
    if (is_array($value)) {
      $params[] = drupal_http_build_query($value, $key);
    }
    // If a query parameter value is NULL, only append its key.
    elseif (!isset($value)) {
      $params[] = $key;
    }
    else {
      // For better readability of paths in query strings, we decode slashes.
      $params[] = $key . '=' . str_replace('%2F', '/', rawurlencode($value));
    }
  }

  return implode('&', $params);
}
