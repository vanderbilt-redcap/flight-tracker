<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/base.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/../classes/Autoload.php";

require_once dirname(__FILE__).'/_header.php';

$username = REDCapManagement::sanitize($_GET['uid'] ?? "");
if (!$username || !MMAHelper::getMMADebug()) {
    $username = Application::getUsername();
}
if (isset($_GET['test'])) {
    echo "username: $username<br>";
    echo "GET: ".REDCapManagement::sanitize($_GET['uid'])."<br>";
}

$hashParam = (Application::getProgramName() == "Flight Tracker Mentee-Mentor Agreements") ? "&hash=".NEW_HASH_DESIGNATION : "";

if(isset($_REQUEST['uid']) && MMAHelper::getMMADebug()){
    $username = Sanitizer::sanitize($_REQUEST['uid']);
    $uidString = "&uid=$username";
    $spoofing = MMAHelper::makeSpoofingNotice($username);
} else {
    $username = Application::getUsername();
    $uidString = "";
    $spoofing = "";
}

if ($username) {
    list($firstName, $lastName) = MMAHelper::getNameFromREDCap($username, $token, $server);
    $welcomeMssg = "Welcome, $firstName!";
} else {
    $firstName = "";
    $lastName = "";
    $welcomeMssg = "Welcome!";
}

$resourcesLinkIfExtant = "";
if ($link = Application::getSetting("mentee_agreement_link", $pid)) {
    if (REDCapManagement::isGoodURL($link)) {
        $institution = Application::getSetting("institution", $pid);
        if (!$institution) {
            $institution = "Your hosting institution";
        }
        $resourcesLinkIfExtant = "<p>$institution suggests <a href='$link'>viewing this resource</a> for further consultation.</p>";
    }
}

?>


<section class="bg-light">
    <div class="container">
        <div class="row">
            <div class="col-lg-12">
                <h2 style="color: #727272;"><?= $welcomeMssg ?></h2>
                <?= $spoofing ?>
                <div class="blue-box" onclick="window.location.href = '<?= Application::link("mentor/index.php").$uidString.$hashParam ?>';"><h1>Start Now</h1></div>

                <?= MMAHelper::makePopupJS() ?>

                <div class="col-lg-4" style="float: right;">
                    <div id="boxa" class="box_bg box_white boxa">
                        <div class="row">
                            <div class="col-lg-7 box_title">Characteristics of a <strong>Successful Mentor</strong></div>
                            <div class="col-lg-5 box_guys">
                                <img src="<?= Application::link("mentor/img/images/box_imgs_03.jpg") ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-lg-12 box_body">
                                <p>A successful mentor is not just an advisor, but a role model, guide and colleague. </p>
                                <p class="lm"><button type="button" class="btn btn-light" onclick="characteristicsPopup('mentor');">Learn More</button></p>
                            </div>
                        </div>
                    </div>
                    <div id="boxb" class="box_bg box_white boxb">
                        <div class="row">
                            <div class="col-lg-7 box_title">Characteristics of a <strong>Successful Mentee</strong></div>
                            <div class="col-lg-5 box_guys">
                                <img src="<?= Application::link("mentor/img/images/box_imgs_06.jpg") ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-lg-12 box_body">
                                <p>The foundation for a successful mentee rests on three “vital signs” of successful mentoring relationships: respect, responsiveness, and accountability.</p>
                                <p class="lm"><button type="button" class="btn btn-light" onclick="characteristicsPopup('mentee');">Learn More</button></p>

                            </div>
                        </div>

                    </div>
                    <div id="boxc" class="box_bg box_white boxc">
                        <div class="row">
                            <div class="col-lg-7 box_title">Additional Resources<br>for a<br>Deeper Dive</div>
                            <div class="col-lg-5 box_guys">
                                <img src="<?= Application::link("mentor/img/images/box_imgs_08.png") ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-lg-12 box_body">
                                <p>These papers in the literature provide opportunities for further exploration.</p>
                                <p class="lm"><button type="button" class="btn btn-light" onclick="characteristicsPopup('resources');">Learn More</button></p>

                            </div>
                        </div>
                    </div>
                </div>

                <?= $resourcesLinkIfExtant ?>
                <p>Welcome to a new way to think about the agreement of collaboration between a Mentee (also referred to here as ‘Scholar’) and Mentor. Mentee-Mentor Scholar Agreements (‘Mentoring Agreement’) function to define a mutually agreed upon set of goals and parameters which provide a foundation for the mentoring relationship.  Ideally, a formal agreement will address a broad range of domains, including the Scholar’s research and education, professional development and career advancement and interactions between the scholar and mentor with respect to support, communication, personal conduct and interpersonal interactions.</p>
                <p>While seen as a valuable mechanism to align expectations between scholars and mentors, and provide a road map for collaboration, agreements are not uniformly employed.  One possible reason for the lack of universal use is the need for a more user friendly, relevant document which may serve as an active guidepost applicable to all levels of the scholar’s career development.  This program was created to provide an improved foundation for the development of agreement documents, and a site for their ongoing access and update.</p>

                <h3>Background</h3>
                <p><img src="<?= Application::link("/mentor/img/temp_image.jpg") ?>" style="float: right; margin-right: 39px;width: 296px;">Every mentee-mentor scientific and career development relationship is different, but there are common features that characterize successful relationships.</p>
                <!-- <p>Traditional Mentoring Agreements provide a static template for each mentee-mentor pairing to address at the start of their formal mentoring relationship.</p> -->

                <p>With this Mentoring Agreement, we seek to:</p>
                <ol>
                    <li>Create the foundation for a discussion and documentation of key ‘domains’ crucial to a productive initiation and maintenance of the mentee-mentor relationship. Example ‘domains’ are:</li>
                    <ol type="a">
                        <li>Meetings, Communication and Work Expectations</li>
                        <li>Financial Support</li>
                        <li>Research</li>
                        <li>Approach to Scholarly Products</li>
                        <li>Career and Professional Development</li>
                    </ol>
                    <li>Create a modifiable Mentoring Agreement which may be created at the start of each relationship and revisited for revision over time as the relationship, scientific efforts, and career development of the mentee mature (e.g., every 12 months).</li>
                    <li>Integrate an Individual Development Plan (IDP) to maximize mentee-mentor conversations around the Scholar’s goals.</li>
                </ol>
                <p>An IDP serves as a “roadmap” to help a Scholar determine, state, and ultimately achieve short- and long-term academic, professional and career goals. Its purpose is to:</p>
                <ol>
                    <li>Articulate and map one’s goals to their career timeline. Goals may include any training and career planning related to research, service, teaching, and/or other scholarly goals.</li>
                    <li>Carefully consider one’s training and professional development at the micro and macro levels.</li>
                    <li>Increase engagement with one’s Mentor(s) to assure alignment and precisely determine needs and goals.</li>
                </ol>
                <p>We hope you find this agreement helpful as you begin or continue your Mentee-Mentor Scholar relationship.</p>
                <p class="indented">Discover more on the blog post entitled <a href="https://edgeforscholars.org/getting-expectations-in-line-with-an-online-mentoring-agreement/">Getting Expectations in Line with an Online Mentoring Agreement</a> on <a href="https://edgeforscholars.org">edgeforscholars.org</a>. Learn about various IDP programs and a growing list of resources like the <a href="https://myidp.sciencecareers.org/">myIDP</a> from the <a href="https://www.aaas.org/">American Association for the Advancement of Science</a>.</p>
            </div>
        </div>
    </div>
</section>

<style type="text/css">
    .indented {
        padding-left: 50px;
    }
    .box_body p:first-of-type{color: #828282}
    .blue-box {
        padding: 40px;
        background-image: linear-gradient(to bottom right, #66d1ff, #4f64db);
        border-radius: 25px;
        cursor: pointer;
        margin: 25px auto;
        max-width: 400px;
        text-align: center !important;
        box-shadow: 6px 6px 4px #444444;
    }

    body {

        font-family: europa, sans-serif !important;
        letter-spacing: -0.5px;
        font-size: 1.3em;
    }

    .h2, h2 {
        font-weight: 700;
        text-align: center;
        color: #727272;
    }

    h3 {
        color: #555555;
        text-align: center;
    }

    .characteristics {
        background-color: #dddddd;
        padding: 20px;
        font-size: 18px;
        max-width: 750px;
    }
    @media screen and (max-width: 1200px) {
        .characteristics {
            max-width: 630px;
        }
    }
    .bg-light {
        background-color: #ffffff!important;
    }
    .box_bg{height: 361px;width: 340px;background-size: contain;    padding: 34px;
        padding-top: 26px;background-image: url(<?= Application::link("mentor/img/box_trans.png") ?>)}
    .box_bg img{width: 142px;
        margin-left: -29px;}
    .box_body{    font-family: synthese, sans-serif;
        font-weight: 200;
        font-size: 17px;
        line-height: 22px;
        padding-top: 22px;
    }
    .box_body button{font-family: europa, sans-serif;}
    .box_white{background-color: #ffffff}
    .box_orange{background-color: #de6339}

    .box_title{    font-size: 23px;
        line-height: 27px;
    }
    .boxa .box_title strong{
        color: #26798a;
    }
    .boxb .box_title strong{
        color: #de6339;
    }
    .btn-light{color: #26798a}
    .lm{text-align: center}
    .lm button{color:#000000;}

    #nprogress .bar {
        background: #1ABB9C
    }
    #nprogress .peg {
        box-shadow: 0 0 10px #1ABB9C, 0 0 5px #1ABB9C
    }
    #nprogress .spinner-icon {
        border-top-color: #1ABB9C;
        border-left-color: #1ABB9C
    }

    h4{
        color:#5b8ac3;
        font-family: proxima-soft, sans-serif;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 14px; letter-spacing: 0px;
    }

</style>



<?php include dirname(__FILE__).'/_footer.php'; ?>

