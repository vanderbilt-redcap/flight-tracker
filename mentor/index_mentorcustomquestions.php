<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

const CUSTOM_QUESTION_LIMIT = 5;

require_once dirname(__FILE__).'/_header.php';


?>

<p>Here is where the custom questions will be made.</p>
<?php for ($i = 0; $i <= CUSTOM_QUESTION_LIMIT; $i++) {
	?>
<br>
<label for="custom_question<?echo $i?>">Custom Question <?echo $i?></label>
<input type="text" name="custom_question<?echo $i?>" id="custom_question1"/>
<br>
<label for="custom_question<?echo $i?>_type">Custom Question <?echo $i?> Type</label>
<select name="custom_qeustion<?echo $i?>_type" id="custom_question<?echo $i?>_type">
    <option>Multiple Choice</option>
    <option>True/False</option>
</select>
<br>
<?php
}

echo Application::generateCSRFTokenHTML();
?>

<script src="<?php echo Application::link('mentor/js/mentor_customquestions.js') ?>"></script>
