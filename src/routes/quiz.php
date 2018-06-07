<?php 

use Slim\Http\Request;
use Slim\Http\Response;
use \Firebase\JWT\JWT;


// GET A QUIZ DETAILS
$app->get('/quiz/{id}', function(Request $request, Response $response, array $args) {
	$sql = "SELECT title, question_answer.id, `type`, `question`, `answer`, `decoy`, `created_at` FROM `question_answer` INNER JOIN quiz ON question_answer.quiz_id = quiz.id WHERE quiz.id = :id";

   try {
     $db = $this->get('db');

     $stmt = $db->prepare($sql);
     $stmt->execute([
       ':id' => $args['id']
     ]);
     $quiz = $stmt->fetchAll(PDO::FETCH_OBJ);
     $db = null;
     $quiz->decoy = explode(", ", $quiz->decoy);
     return $response->withJson($quiz);
   }
   catch (PDOException $e) {
     $error = ['error' => ['text' => $e->getMessage()]];
     return $response->withJson($error);
   }
});

// GET ALL QUIZ
$app->get('/quiz', function(Request $request, Response $response, array $args) {
	$sql = "SELECT * FROM `quiz`";
   try {
     $db = $this->get('db');

     $stmt = $db->query($sql);
     $quiz = $stmt->fetchAll(PDO::FETCH_OBJ);
     $db = null;
     return $response->withJson($quiz);
   }
   catch (PDOException $e) {
     $error = ['error' => ['text' => $e->getMessage()]];
     return $response->withJson($error);
   }
});


// CREATE A QUIZ
$app->post('/quiz', function(Request $request, Response $response, array $args) {
 if ($request->getAttribute("jwt")['isAdmin'] != 1) {
   $error = ['error' => ['text' => 'Permission denied']];
   return $response->withJson($error);
 }

 $title = $request->getParam('title');

 $sql = "INSERT INTO `quiz`(`title`) VALUES (:title)";
 try {
   $db = $this->get('db');
   $stmt = $db->prepare($sql);
   $stmt->execute([
     ':title' => $title
   ]);
 }
 catch (PDOException $e) {
   $error = ['error' => ['text' => $e->getMessage()]];
   return $response->withJson($error);
 }

 $question_answer = $request->getParam('question_answer');

 $data = [];
 foreach ($question_answer as $qa) {
   $data[] = $qa['type'];
   $data[] = $qa['question'];
   $data[] = $qa['answer'];
   $data[] = implode(", ", $qa['decoy']);
   $data[] = date("Y-m-d H:i:s");
   $data[] = $db->lastInsertId();
 }

 $count = count($data);
 $add = [];
 for ($i=0; $i < $count; $i = $i + 6) { 
   $add[] = "(?, ?, ?, ?, ?, ?)";
 }

 $sql = "INSERT INTO `question_answer`(`type`,`question`, `answer`, `decoy`, `created_at`, `quiz_id`) VALUES " . implode(',', $add);
 try {
   $db = $this->get('db');
   $stmt = $db->prepare($sql);
   $stmt->execute($data);

   $data = ["notice"=>["type"=>"success", "text" => "Quiz sucessfully added"]];
   return $response->withJson($data);
 }
 catch (PDOException $e) {
   $error = ['error' => ['text' => $e->getMessage()]];
   return $response->withJson($error);
 }
});





// Kirim jawaban user untuk diproses
$app->post('/answer', function(Request $request, Response $response, array $args) {

 $answers = $request->getParam('answers');
 $quiz_id = $request->getParam('quiz_id');
 if (filter_var($quiz_id, FILTER_VALIDATE_INT) === FALSE) {
   $error = ['error' => ['text' => 'Invalid quiz id']];
   return $response->withJson($error);
 }
 $user_id = $request->getAttribute("jwt")['id'];

 $sql = "INSERT INTO user_answer(`answer`,`qa_id`, `user_id`) VALUES ";
 
 $placeholders = [];
 $user_answer = [];
 $sql_add = [];
 foreach ($answers as $answer) {
   $sql_add[] = "(?, ?, " . $user_id . ")";
   $placeholders[] = $answer["answer"];
   $placeholders[] = $answer["qa_id"];
   $user_answer[$answer["qa_id"]] = $answer["answer"];
 }

 $sql .= implode(",", $sql_add);

 try {
   $db = $this->get('db');
   $stmt = $db->prepare($sql);
   $stmt->execute($placeholders);
 }
 catch (PDOException $e) {
   $error = ['error' => ['text' => $e->getMessage()]];
   return $response->withJson($error);
 }

 $question_marks = array_map(function($element) {
   return '?';
 }, $user_answer);

 $sql = "SELECT `id` as `qa_id`, `answer` as `correct_answer` FROM `question_answer` WHERE `id` IN (" . implode(',', $question_marks) . ") AND quiz_id = " . $quiz_id;

 try {
   $db = $this->get('db');
   $stmt = $db->prepare($sql);
   $stmt->execute(array_keys($user_answer));
   $results = $stmt->fetchAll();

   $score = 0;
   $max = 0;

   foreach ($results as $result) {
     $key = $result['qa_id'];
     if($user_answer[$key] == $result['correct_answer']) {
       $score++;
     }
     $max++;
   }

   $grade = $score * 100 / $max;

   $sql = "INSERT INTO `user_score`(`score`, `quiz_id`, `user_id`) VALUES (:grade,:quiz_id,:user_id)";
   $stmt = $db->prepare($sql);
   $stmt->execute([
     ':grade' => $grade,
     ':quiz_id' => $quiz_id,
     ':user_id' => $user_id
   ]);

   $data = ["notice"=>["type"=>"success", "text" => "Answer successfully submitted"]];
   return $response->withJson($data);
 }
 catch (PDOException $e) {
   $error = ['error' => ['text' => $e->getMessage()]];
   return $response->withJson($error);
 }
});