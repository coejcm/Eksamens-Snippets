<?php
/**
 * This is the facebook-Callback file. This is where the authentication magic happens. 
 * 
 * We use this file to obtain the facebook access token, get the user information and persist it in our database. 
 * We also generate an ID, to send back to the user. This ID will work as a key to access user information through our rest api.
 */
session_start();
require_once '../../vendor/autoload.php';
require_once '../../api/config/database.php';
require_once '../id-generator.php';

$homepath = $_SESSION['home'];
#get timestamp
$now = date("Y-m-d h:m:s");
#create new instance of db and create connection
$database = new Database();
$conn = $database->getConnection();

#Get new id case of first time login. 
$id = generateRandomString(50);
$return_id;

#define a new instance of facebook app.
$fb = new Facebook\Facebook([
    'app_id' => 'xxxx',
    'app_secret' => 'xxxxx',
    'default_graph_version' => 'v2.12',
]);
$helper = $fb->getRedirectLoginHelper();

//This line is a workaround to avoid Cross Site Fogery error.
if (isset($_GET['state'])) {$helper->getPersistentDataHandler()->set('state', $_GET['state']);}

#generate new accesstoken
try {
    //vi forsøger så at generere en acces token.
    $accessToken = $helper->getAccessToken();
} catch (Facebook\Exceptions\FacebookResponseException $e) {

    // When Graph returns an error
    echo 'Graph returned an error: ' . $e->getMessage();
    exit;
} catch (Facebook\Exceptions\FacebookSDKException $e) {
    //header('Location: /facebook-stuff/ups.php');
    // When validation fails or other local issues
    echo 'Facebook SDK returned an error: ' . $e->getMessage();
    exit;
}

//if we get a token 
if (isset($accessToken)) {
    # extend token lifetime from 2 hours to 60 days.
    // getting short-lived access token
    $temp_token = (string) $accessToken;
    // OAuth 2.0 client handler
    $oAuth2Client = $fb->getOAuth2Client();
    // Exchanges a short-lived access token for a long-lived one
    $longLivedAccessToken = $oAuth2Client->getLongLivedAccessToken($temp_token);
    $accessToken = (string) $longLivedAccessToken;
}
#get user info
$fb_user = $fb->get('/me?fields=id,name', $accessToken);
$fb_user = $fb_user->getGraphUser();

#get pages owned by user
$pages = $fb->get('/me/accounts', $accessToken);
$pages = $pages->getGraphEdge()->asArray();

#prepare variables for logic and sql queries
$uid = $fb_user['id'];
$u_name = $fb_user['name'];
$page_to_arr = [];
$db_array=[];

#Transform fb_pages into array.
foreach ($pages as $page) {
    $page_id = $page['id'];
    $page_name = $page['name'];
    $page_category = $page['category'];
    $page_to_arr[] = [id => $page_id, name => $page_name, cat => $page_category];
}

// $testarr = [id => '12400500583913', name => 'test', cat => 'test'];
// $page_to_arr[] = $testarr;


//Check connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

#Get useinfo to see if user already exists in the database.
$find_usr_sql = "SELECT id, userID FROM fb_users WHERE userID=$uid";
$result = $conn->query($find_usr_sql);

#if user already exists just update token and check if new facebook pages has arrived or disappeared since last login session.
if ($result->num_rows > 0) {
    try{
        #We use transactions to avoid corrupt data - Persist everything or nothing.
        $conn->begin_transaction(MYSQLI_TRANS_START_READ_WRITE);
        #User id is unique, so we only have one row. In case you want to reuse the 3 lines of code below, make sure you only have one row.
        while($row = $result->fetch_assoc()) {
            $return_id = $row["id"];
        }

        //Then update the facebook authentication token in the database.
        $update_token_query = "UPDATE fb_tokens SET token='$accessToken' WHERE userID='$uid'"; 
        if ($conn->query($update_token_query) === true) {
            echo "Token updated successfully <br>";
        } else {
            throw new Exception('Something went wrong in update token query: '. $conn->error . '<br>');
        }
        
        $find_companies_query = "SELECT companyID, companyName, companyCategory FROM fb_companies WHERE userID=$uid";
        $res = $conn->query($find_companies_query);
        
        while($row = mysqli_fetch_array($res)){
            $companyID = $row['companyID'];
            $companyName = $row['companyName'];
            $companyCategory = $row['companyCategory'];
            $db_array[] = [id => $companyID, name => $companyName, cat => $companyCategory];
        }
    
        if(sizeof($db_array) < sizeof($page_to_arr)) {
            $new_companies_array = array_diff_assoc($page_to_arr, $db_array);
            foreach ($new_companies_array as $new_company) {
                $com_id = $new_company['id'];
                $com_name = $new_company['name'];
                $com_cat = $new_company['cat'];
                $insert_companies_query =  "INSERT INTO fb_companies (companyID, userID, companyName, companyCategory, created)
                VALUES ('$com_id', '$uid', '$com_name', '$com_cat', '$now')";
                if ($conn->query($insert_companies_query) === true) {
                    echo "Company record for ". $com_name ." created successfully <br>";
                } else {
                    throw new Exception('Something went wrong in insert another company query for '. $com_name .': '. $conn->error);
                }                        
            }
        }
        //TBD: BY NOW THE ALGORITHM DOES NOT DELETE COMPANIES FOUND IN DB BUT NOT FOUND IN PAGE TO ARRAY - FIGURE IT OUT IN VERSION II ---- SEE INSPIRATION CODE EX IN THE INSP FILE.
        
        
        $conn->commit();
    } catch (Exception $e) {
        echo 'Caught exception: ',  $e->getMessage(), "\n";
    }
} 
#else create new user and companies in database.
else {
    try {
        $conn->begin_transaction(MYSQLI_TRANS_START_READ_WRITE);

        $insert_user_query = "INSERT INTO fb_users (id, userID, userName, created)
        VALUES ('$id', '$uid', '$u_name', '$now')";

        if ($conn->query($insert_user_query) === true) {
            echo "User record created successfully <br>";
        } else {
            throw new Exception('Something went wrong in insert user query: '. $conn->error);
        }

        $insert_token_query = "INSERT INTO fb_tokens (userID, token, created)
        VALUES ('$uid', '$accessToken', '$now')";

        if ($conn->query($insert_token_query) === true) {
        echo "Token record created successfully <br>";
        } else {
        throw new Exception('Something went wrong in insert token query: ' . $conn->error);
        }
        
        if(!empty($page_to_arr)){
            foreach ($page_to_arr as $company) {
                $com_id = $company['id'];
                $com_name = $company['name'];
                $com_cat = $company['cat'];
                $insert_companies_query =  "INSERT INTO fb_companies (companyID, userID, companyName, companyCategory, created)
                VALUES ('$com_id', '$uid', '$com_name', '$com_cat', '$now')";
                if ($conn->query($insert_companies_query) === true) {
                    echo "Company record for ". $com_name ." created successfully <br>";
                } else {
                    throw new Exception('Something went wrong in insert company query for '. $com_name .': '. $conn->error);
                }
            }     
        }
        $return_id = $id;
        $conn->commit();
    } catch (Exception $e) {
        echo 'Caught exception: ',  $e->getMessage(), "\n";
        header('Location: '.$homepath.'/wp-content/plugins/checkmate_instant_share/admin/partials/fb/fb_auth.php?facebookErrorMsg='.$e->getMessage());
    }
}

header('Location: '.$homepath.'/wp-content/plugins/checkmate_instant_share/admin/partials/fb/fb_auth.php?id='.$return_id);
?>