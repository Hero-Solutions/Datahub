<?php

namespace DataHub\PipelineBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DataHubOutputController extends Controller
{
    /**
     * @Route("log", name="datahub_log")
     */
    public function dataHubOutputAction(Request $request)
    {
        $filename = $this->getParameter('pipeline_logfile');
        if($filename === '') {
            return new Response('This Datahub is not configured to have a logfile accessible through browser.', 404);
        } else if(!file_exists($filename)) {
            return new Response('Sorry, this logfile does not exist.', 404);
        } else {
            $output = file_get_contents($filename);
            $output = str_replace("\n", "<br/>", $output);
            $output = preg_replace("/\[[0-9]+\] - Datahub::Factory::CLI::setup_logging /", "UTC", $output);
            $output = str_replace("/usr/local/share/perl/5.26.1/Datahub/Factory/CLI.pm (98) : Logger activated - level WARN - config loaded from string:", "", $output);
            $output = str_replace("[92m", "", $output);
            $output = str_replace("[33m", "", $output);
            return new Response($output);
        }
    }
}
