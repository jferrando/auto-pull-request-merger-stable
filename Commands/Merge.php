<?php



/**
 * this is the basic class used to check our pull requests
 */
class Merge
{
    const FORCE_BUILD_CONFIRMATION = false; // true if you want to ensure your CI suite marked this PR as stable
    const MAX_OPEN_PULL_REQUESTS = 25;
    const HIPCHAT_TOKEN = ''; // this is the hipchat token of the room you want to be notified
    const REQUIRED_POSITIVE_REVIEWS = 2; // this is the number of positive reviews you require to merge the pull request

    protected $validPositiveCodeReviewMessages = array(":+1: to data", "+1 to data", ":+1: to render", "+1 to render", ":+1: to business", "+1 to business");
    protected $validBlockerCodeReviewMessages = array("[B]", "[b]");
    protected $validUatOKMessages = array("UAT OK");

    protected $user = "";
    protected $password = "";
    protected $owner = '';
    protected $repo = '';
    protected $basePath = '~/projects/auto-pull-request-merger-stable/';
    protected $_client;


    /**
     * Execution
     *
     * @return int|void
     */
    public function pullRequest($user = null, $password = null, $owner = null, $repo = null)
    {

        $startTime = microtime(true);

        GitHubAutoloader::getInstance();

        if (!empty($user)) {
            $this->user = $user;
        }
        if (!empty($password)) {
            $this->password = $password;
        }

        if (!empty($owner)) {
            $this->owner = $owner;
        }

        if (!empty($repo)) {
            $this->repo = $repo;
        }


        $this->_client = new GitHubApi(new  GitHubCurl());

        $this->_client->auth(
            $this->user,
            $this->password,
            GitHubApi::AUTH_HTTP
        );
	$requestsList = $this->_getOpenPullRequests();
	var_dump($requestsList);die();
        for ($i = count($requestsList) - 1; $i >= 0; $i--) {
            $pullRequest = $requestsList[$i];

            $comments = $this->_getPullRequestComments($pullRequest->number);
            if (!$this->_canBeMerged($comments, $pullRequest->head->sha, $pullRequest->number)) {
                continue;
            }

            $this->_mergePullRequest($pullRequest->number);
            break;
        }
        $endTime = microtime(true);
        $time = sprintf("%0.2f", $endTime - $startTime);
        echo ("Process finished: Parsed " . count($requestsList) . " open pull requests in $time seconds\n");
    }


    /**
     * Get the open pull requests of the repo
     * @return array
     */
    protected function _getOpenPullRequests()
    {
        try {

            $prs = $this->_client->get(
                '/repos/:owner/:repo/pulls',
                array(
                    'owner' => $this->owner,
                    'repo' => $this->repo
                )
            );

            if (count($prs) >= self::MAX_OPEN_PULL_REQUESTS) {
                $this->_sendMessage(
                    "Hey! @all We have " . count($prs) .
                        " review code or die!!"
                );
            }

            return $prs;

        } catch (\Exception $e) {
            echo "$e\n";

            return array();
        }
    }


    /**
     * Get the comments of a pull request
     * @param integer $number
     *
     * @return array
     */
    protected function _getPullRequestComments($number)
    {
        $prs = $this->_client->get(
            '/repos/:owner/:repo/issues/:number/comments',
            array(
                'owner' => $this->owner,
                'repo' => $this->repo,
                'number' => $number
            )
        );

        return $prs;

    }


    /**
     * Merges a pull request
     * @param integer $number
     */
    protected function _mergePullRequest($number)
    {
        try {
            $this->_client->put(
                '/repos/:owner/:repo/pulls/:number/merge',
                array(
                    'owner' => $this->owner,
                    'repo' => $this->repo,
                    'number' => $number
                ),
                array(
                    'message' => 'test',
                )
            );
            echo("Merged pull $number\n");

        } catch (\Exception $e) {
            $ex = json_decode($e->getMessage());
            $this->_addCommentToPullRequest($number, $ex->message);
            echo("Cannot merge $number\n");

        }

    }


    /**
     * Check if a pull request can be merged
     *
     * based on 3 "+1" and no blocker
     *
     * @param array  $comments
     * @param string $sha
     * @param int    $pullRequestNumber
     *
     * @return bool
     */
    protected function _canBeMerged($comments, $sha, $pullRequestNumber)
    {
        $passedCodeReview = $this->_passedCodeReview($comments, $sha, $pullRequestNumber);
        if ($passedCodeReview) {
            $this->_prepareTestingEnvironment($pullRequestNumber);
            if ($this->_passedUAT($comments)) {
                return true;
            }

            return false;
        }
    }

    protected function _prepareTestingEnvironment($pullRequestNumber)
    {
        // TODO prepare the environment
        // basic version, only checkout the code
        $jiraIssueNumber = $this->_findJiraIssueNumber($pullRequestNumber);
        if (!$jiraIssueNumber) {
            $jiraIssueNumber = "pull-request-$pullRequestNumber";
            echo "cannot find jira issue number for PR $pullRequestNumber, we use a fake branch name";
        }
        $shellCommand = "{$this->basePath}prepareTestEnv.sh $pullRequestNumber $jiraIssueNumber";
        echo "Preparing local branch $jiraIssueNumber merging master branch with pull request $pullRequestNumber\n";
        shell_exec($shellCommand);
    }

    protected function _passedUAT($comments)
    {
        // TODO : check if there is a test ok message
        foreach ($comments as $comment) {
            if ($this->_isAUatOK($comment)) {
                return true;
            }
        }

        return false;
    }

    protected function _passedCodeReview($comments, $sha, $pullRequestNumber)
    {
        $pluses = 0;
        $blocker = false;
        if (!$this->_isBuildOk($sha) and self::FORCE_BUILD_CONFIRMATION) {
            echo("Pull request $pullRequestNumber has no build success confirmation message \n");

            return false;
        }

        foreach ($comments as $comment) {
            if ($this->_isACodeReviewOK($comment)) {
                ++$pluses;
                $blocker = false;
            } else {
                if ($this->_isACodeReviewKO($comment)) {
                    echo("Blocker found\n");

                    $blocker = true;
                    break;
                }
            }
        }

        if ($pluses >= self::REQUIRED_POSITIVE_REVIEWS && !$blocker) {
            return true;
        }

        $this->_addCommentToPullRequest(
            $pullRequestNumber,
            "Will not merge pull request $pullRequestNumber,only $pluses positive reviews"
        );
        echo("Pull request $pullRequestNumber has only $pluses positive reviews\n");

    }


    /**
     * Check if the build was ok
     * @param string $sha
     *
     * @return bool
     */
    protected
    function _isBuildOk(
        $sha
    ) {
        $response = $this->_client->get(
            '/repos/:owner/:repo/statuses/:sha',
            array(
                'owner' => $this->owner,
                'repo' => $this->repo,
                'sha' => $sha
            )
        );
        $last = isset($response[0]) ? $response[0] : null;

        return (!empty($last) && $last->state == 'success');
    }


    /**
     * Add a comment to a pull request
     * @param integer $number
     * @param string  $message
     */
    protected
    function _addCommentToPullRequest(
        $number,
        $message
    ) {
        $this->_client->post(
            '/repos/:owner/:repo/issues/:number/comments',
            array(
                'owner' => $this->owner,
                'repo' => $this->repo,
                'number' => $number
            ),
            array(
                'body' => $message,
            )
        );
    }


    /**
     * Send a message to hipchat
     * @param string $msg
     *
     * @return null
     */
    protected
    function _sendMessage(
        $msg
    ) {
        try {
            $hc = new HipChat(self::HIPCHAT_TOKEN);
            $hc->message_room('work', 'Pull-Requester', $msg, false, HipChat::COLOR_RED);
        } catch (\Exception $e) {
            echo "\n HIPCHAT API NOT RESPONDING \n";
            echo "$e \n";
        }
    }

    protected function _findJiraIssueNumber($pullRequestNumber)
    {

        $jiraIssue = null;
        try {
            $prs = $this->_client->get(
                '/repos/:owner/:repo/pulls/:number',
                array(
                    'owner' => $this->owner,
                    'repo' => $this->repo,
                    'number' => $pullRequestNumber
                )
            );
            $title = $prs->title;
            if (preg_match("/\#[A-Za-z]+\-[0-9]+/", $title, $matches)) {
                $jiraIssue = $matches[0];
            }
        } catch (GitHubCommonException $e) {
            echo "Exception: $e , request: /repos/" . $this->owner . "/" . $this->repo . "/pulls/" . $pullRequestNumber . "/";
        }
        $jiraIssue = trim($jiraIssue, "#");

        return $jiraIssue;

    }

    private function _isACodeReviewOK($comment)
    {
        foreach ($this->validPositiveCodeReviewMessages as $positiveMessage) {
            if (false !== strpos($comment->body, $positiveMessage)) {
                return true;
            }
        }

        return false;
    }

    private function _isACodeReviewKO($comment)
    {

        foreach ($this->validBlockerCodeReviewMessages as $blockerMessage) {
            if (false !== strpos($comment->body, $blockerMessage)
            ) {
                return true;
            }
        }

        return false;
    }

    private function _isAUatOK($comment)
    {
        foreach ($this->validUatOKMessages as $uatOKMessage) {
            if (false !== strpos(strtolower($comment->body), strtolower($uatOKMessage))
            ) {
                return true;
            }
        }

        return false;
    }

}
