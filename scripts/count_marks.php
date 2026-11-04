<?php
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sbms','root','');
$sql="SELECT COALESCE(school_year,'<null>') as sy, COUNT(*) as c FROM student_marks GROUP BY COALESCE(school_year,'<null>')";
$stmt=$db->query($sql);
$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r){ echo $r['sy'].' -> '.$r['c']."\n"; }
?>