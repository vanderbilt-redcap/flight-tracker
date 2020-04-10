<?php

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="newFaculty.csv"');

echo "First Name,Preferred Name,Middle Name,Last Name,Email,Additional Institution(s),Gender [Male or Female],Date-of-Birth [MM-DD-YYYY],Race [American Indian or Alaska Native; Asian; Native Hawaiian or Other Pacific Islander; Black or African American; White; More Than One Race; or Other],Ethnicity [Hispanic/Latino or Non-Hispanic],Disadvantaged [Y; N; or Prefer Not To Answer],Disability [Y or N],Citizenship [US born; Acquired US; Permanent US Residency; or Temporary Visa],Primary Mentor\n";

