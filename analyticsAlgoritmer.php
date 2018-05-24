<?php
    // namespace googleapi;
    require_once '../config/response.php';
    header("Content-Type:application/json");
        // Load the Google API PHP Client Library.
    require_once '../../vendor/autoload.php';

    //Connect to analytics 
    $analytics = initializeAnalytics();
    //Get information out with view ID 
    $response = getReport($analytics);
    //Cut away unnecessary data from response (green square)
    $sortedData = cleanResults($response);
    //Isolate campaigns (Yellow Square) 
    $campaignArrays = isolateCampaigns($sortedData);
    //Attach relevant sessions to each campain (Orange Square)
    $updatedValues = updateValues($campaignArrays, $sortedData);
    //Sort data to fit into Heighcharts (Red Square).
    $chartArr = chartifyData($updatedValues);
    //Send back response with chart-ready array
    response(200, 'posts found', $chartArr);

    /**
     * Initializes an Analytics Reporting API V4 service object.
     *
     * @return An authorized Analytics Reporting API V4 service object.
     */
    function initializeAnalytics()
    {

    // Use the developers console and download your service account
    // credentials in JSON format. Place them in this directory or
    // change the key file location if necessary.
    $KEY_FILE_LOCATION = __DIR__ . 'XXXXXXX';

    // Create and configure a new client object.
    $client = new Google_Client();
    $client->setApplicationName("Get graph data");
    $client->setAuthConfig($KEY_FILE_LOCATION);
    $client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
    $analytics = new Google_Service_AnalyticsReporting($client);

    return $analytics;
    }


    /**
     * Queries the Analytics Reporting API V4.
     *
     * @param service An authorized Analytics Reporting API V4 service object.
     * @return The Analytics Reporting API V4 response.
     */
    function getReport($analytics) {

        // Replace with your view ID, for example XXXX.
        $VIEW_ID = "XXXXXXXX";

        // Create the DateRange object.
        $dateRange = new Google_Service_AnalyticsReporting_DateRange();
        $dateRange->setStartDate("14daysAgo");
        $dateRange->setEndDate("today");

        // Create the Metrics object.
        $sessions = new Google_Service_AnalyticsReporting_Metric();
        $sessions->setExpression("ga:sessions");
        $sessions->setAlias("sessions");
        
        $pageviews = new Google_Service_AnalyticsReporting_Metric();
        $pageviews->setExpression("ga:pageviews");
        $pageviews->setAlias("pageviews");

        //Create the Dimensions object.
        $campaign = new Google_Service_AnalyticsReporting_Dimension();
        $campaign->setName("ga:campaign");

        //Create the Dimensions object.
        $source = new Google_Service_AnalyticsReporting_Dimension();
        $source->setName("ga:source");

        // Create the ReportRequest object.
        $request = new Google_Service_AnalyticsReporting_ReportRequest();
        $request->setViewId($VIEW_ID);
        $request->setDateRanges($dateRange);
        $request->setMetrics(array($sessions ));
        $request->setDimensions(array($campaign, $source));
        // $request->setOrderBys($ordering);

        $body = new Google_Service_AnalyticsReporting_GetReportsRequest();
        $body->setReportRequests( array($request) );
        return $analytics->reports->batchGet( $body );
    }


    /**
     * Parses and prints the Analytics Reporting API V4 response.
     *
     * @param An Analytics Reporting API V4 response.
     * 
     * CleanResults, er en google metode, som jeg har genbrugt og bygget videre på,
     * således at jeg så nemt som muligt, kan få data ud fra google og derefter skrive 
     * mine egne sorteringsalgoritmer. 
     */


    function cleanResults($reports){
    $sortedResults = [];
    //For hver rapport i google dataen, som vi modtager,
    for ( $reportIndex = 0; $reportIndex < count( $reports ); $reportIndex++ ) {
        //gemmer vi de dele af arrayet, som vi skal sortere. 
        $report = $reports[ $reportIndex ];
        //Header på metrics og dimentions.
        $header = $report->getColumnHeader();
        //sorterer dimention headers for sig selv
        $dimensionHeaders = $header->getDimensions();
        //sorterer metrics headers for sig selv
        $metricHeaders = $header->getMetricHeader()->getMetricHeaderEntries();
        //hiver de forskellige rækker i google datasættet ud.
        $rows = $report->getData()->getRows();

        //For hver række i datasættet,
        for ( $rowIndex = 0; $rowIndex < count($rows); $rowIndex++) {
        $temp = [];
        $row = $rows[ $rowIndex ];
        $dimensions = $row->getDimensions();
        $metrics = $row->getMetrics();
        //tager vi hver dimention og smider ind i et temp arrayet med header som key og dimension som value.
        for ($i = 0; $i < count($dimensionHeaders) && $i < count($dimensions); $i++) {
            $temp[$dimensionHeaders[$i]] = $dimensions[$i];
        }
        
        // og tager hver metric og smider ind i et temp arrayet med header som key og metric som value.
        for ($j = 0; $j < count($metrics); $j++) {
            $values = $metrics[$j]->getValues();
            for ($k = 0; $k < count($values); $k++) {
            $entry = $metricHeaders[$k];
            $temp[$entry->getName()] = $values[$k];
            }
        
        }
        //hvor efter vi smider temp ind i $sortedResults
        $sortedResults[] = $temp;
        }
    }
    //og returnerer arrayet. Nu har vi så et array af alle rækkerne med dertilhørende dimensions og metrics.
    return $sortedResults;
    }

    //her isolerer vi kampangerne, så vi har får et array af de forskellige kampanger, uden redundans.
    function isolateCampaigns($array){
    $camp_arr = [];
    foreach ($array as $post) {
        if(!in_array($post['ga:campaign'], $camp_arr) && $post['ga:campaign'] != '(not set)'){
        $camp_arr[] = $post['ga:campaign'];
        } 
    }  
    return $camp_arr;
    }

    //her sætter vi så session fra de sociale medier, ind under hver kampange.
    function updateValues($ca, $sa) {
    $ca_arr =[];
    foreach ($sa as $s_element) {
        foreach ($ca as $c_element) {
        if( $c_element == $s_element['ga:campaign']){
            $ca_arr[$s_element['ga:campaign']][$s_element['ga:source']]=$s_element['sessions'];
        }
        }
    }
    return $ca_arr;
    }

    function chartifyData($arr){
    $campaigns = [];
    $facebook = [];
    $linkedin = [];
    foreach ($arr as $hej => $elm) {
        $campaigns[] = $hej;
        if(sizeof($elm)<2){
        foreach ($elm as $key => $value) {
            if($key == 'facebook'){
            $facebook[] = (int)$value;
            } else {
            $facebook[] = 0;        
            }
        }
        foreach ($elm as $key => $value) {
            // echo $key.':'.$value;
            if($key == 'LinkedIn'){
            $linkedin[] = (int)$value;
            } else {
            $linkedin[] = 0;        
            }
        }
        } else {
        foreach ($elm as $key => $value) {
            if($key == 'facebook'){
            $facebook[] = (int)$value;
            } 
        }
        foreach ($elm as $key => $value) {
            // echo $key.':'.$value;
            if($key == 'LinkedIn'){
            $linkedin[] = (int)$value;
            }
        }
        }
    }
    $result = ['campaigns'=>$campaigns, 'facebook'=>$facebook, 'linkedin'=>$linkedin];
    // $result = [$campaigns, $facebook, $linkedin];
    return $result;
    }
        

?>