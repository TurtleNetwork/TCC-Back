<?php

namespace App\Console\Commands;

use App\Project;
use Illuminate\Console\Command;
use GitLab\Connection;
use GuzzleHttp\Client;
use stdClass;
use Symfony\Component\Yaml\Yaml;

class UpdateSiteProposals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ffs:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the files required for jeykll site';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $response = [
            $this->ideaProposals(),
            $this->fundingRequiredProposals(),
            $this->workInProgressProposals(),
        ];
        $json = json_encode($response, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        \Storage::put('ffs.json', $json);
    }

    public function ideaProposals()
    {
        $group = new stdClass();
        $group->stage = 'Ideas';
        $responseProposals = [];

        $ideas = [];
        $connection = new Connection(new Client());
        $mergeRequests = $connection->mergeRequests('opened');
        foreach ($mergeRequests as $mergeRequest) {
            $newFiles = $connection->getNewFiles($mergeRequest->iid);
            if (sizeof($newFiles) != 1) {
                $this->error ("Skipping MR #$mergeRequest->id '$mergeRequest->title': contains multiple files");
                continue;
            }
            $filename = $newFiles[0];
            if (!preg_match('/.+\.md$/', $filename)) {
                $this->error("Skipping MR #$mergeRequest->id '$mergeRequest->title': doesn't contain any .md file");
                continue;
            }
            if (basename($filename) != $filename) {
                $this->error("Skipping MR #$mergeRequest->id '$mergeRequest->title': $filename must be in the root folder");
                continue;
            }            
            if (in_array($filename, $ideas)) {
                $this->error("Skipping MR #$mergeRequest->id '$mergeRequest->title': duplicated $filename, another MR #$ideas[$filename]->id");
                continue;
            }
            $project = Project::where('filename', $filename)->first();
            if ($project) {
                $this->error("Skipping MR #$mergeRequest->id '$mergeRequest->title': already have a project $filename");
                continue;
            }
            $this->info("Idea MR #$mergeRequest->id '$mergeRequest->title': $filename");

            $prop = new stdClass();
            $prop->name = htmlspecialchars(trim($mergeRequest->title), ENT_QUOTES);
            $prop->{'gitlab-url'} = htmlspecialchars($mergeRequest->web_url, ENT_QUOTES);
            $prop->author = htmlspecialchars($mergeRequest->author->username, ENT_QUOTES);
            $prop->date = date('F j, Y', strtotime($mergeRequest->created_at));
            $responseProposals[] = $prop;
        }

        $group->proposals = $responseProposals;
        return $group;
    }

    public function fundingRequiredProposals()
    {
        $group = new stdClass();
        $group->stage = 'Funding Required';
        $responseProposals = [];
        $proposals = Project::where('gitlab_state', 'merged')->where('state', 'FUNDING-REQUIRED')->get();
        foreach ($proposals as $proposal) {
            $prop = new stdClass();
            $prop->name = $proposal->title;
            $prop->{'gitlab-url'} = $proposal->gitlab_url;
            $prop->{'local-url'} = '#';
            $prop->{'donate-url'} = url("projects/{$proposal->payment_id}/donate");
            $prop->percentage = $proposal->percentage_funded;
            $prop->amount = $proposal->target_amount;
            $prop->{'amount-funded'} = $proposal->amount_received;
            $prop->author = $proposal->gitlab_username;
            $prop->date = $proposal->gitlab_created_at->format('F j, Y');
            $responseProposals[] = $prop;
        }
        $group->proposals = $responseProposals;
        return $group;
    }

    public function workInProgressProposals()
    {
        $group = new stdClass();
        $group->stage = 'Work in Progress';
        $responseProposals = [];
        $proposals = Project::where('gitlab_state', 'merged')->where('state', 'WORK-IN-PROGRESS')->get();
        foreach ($proposals as $proposal) {
            $prop = new stdClass();
            $prop->name = $proposal->title;
            $prop->{'gitlab-url'} = $proposal->gitlab_url;
            $prop->{'local-url'} = '#';
            $prop->milestones = 0;
            $prop->{'milestones-completed'} = 0;
            $prop->{'milestones-percentage'} = 0;
            $prop->percentage = $proposal->percentage_funded;
            $prop->amount = $proposal->target_amount;
            $prop->{'amount-funded'} = $proposal->amount_received;
            $prop->author = $proposal->gitlab_username;
            $prop->date = $proposal->gitlab_created_at->format('F j, Y');
            $responseProposals[] = $prop;
        }
        $group->proposals = $responseProposals;
        return $group;

    }
}
