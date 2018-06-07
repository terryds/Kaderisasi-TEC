<?php
use Slim\Http\Request;
use Slim\Http\Response;
use \Firebase\JWT\JWT;

$app->post('/login', function (Request $request, Response $response, array $args) {
 
    $input = $request->getParsedBody();
    $sql = "SELECT * FROM users WHERE email= :email";
    $sth = $this->db->prepare($sql);
    $sth->bindParam("email", $input['email']);
    $sth->execute();
    $user = $sth->fetchObject();
 
    // verify email address.
    if(!$user) {
        return $this->response->withJson(['error' => true, 'message' => 'These credentials do not match our records.']);
    }
 
    // verify password.
    if (!password_verify($input['password'],$user->password)) {
        return $this->response->withJson(['error' => true, 'message' => 'These credentials do not match our records.']); 
    }
 
    $settings = $this->get('settings'); // get settings array.
    $token = JWT::encode([
      'id' => $user->id,
      'email' => $user->email,
      'name' => $user->name,
      'isAdmin' => $user->isAdmin
    ], $settings['jwt']['secret'], "HS256");
 
    return $this->response->withJson(['token' => $token]);
});


// REGISTRATION
$app->post('/registration', function(Request $request, Response $response, array $args) {

  $name = $request->getParam('name');
  if(filter_var($request->getParam('email'), FILTER_VALIDATE_EMAIL) === FALSE) {
    var_dump($request->getParam('email'));
    $error = ['error' => ['text' => "$email is not a valid email address"]];
    return $response->withJson($error);
  }
  $email = $request->getParam('email');
  $password = password_hash($request->getParam('password'), PASSWORD_DEFAULT);
  $created_at = date("Y-m-d H:i:s");
  $lunas = 0;

  if ($request->getParam('coupon')) {
    $coupon = $request->getParam('coupon');
    $sql = "SELECT EXISTS(SELECT * from COUPONS where COUPON = :coupon) as ada_kupon";

    try {
      $db = $this->get('db');
      $stmt = $db->prepare($sql);
      $stmt->execute([
        ':coupon' => $coupon
      ]);
      $result = $stmt->fetch();
      
      if($result['ada_kupon'] == 1) {
        $lunas = 1;
      }
      else {
        return $response->withJson(['error'=>['text' => 'Invalid coupon']]);
      }
    }
    catch (PDOException $e) {
      $error = ['error' => ['text' => $e->getMessage()]];
      return $response->withJson($error);
    }

  }
  
  $verified = md5(uniqid(rand(),true));
  $isAdmin = 0;

  $sql = "INSERT INTO `users`(`name`, `email`, `password`, `created_at`, `lunas`, `verified`, `isAdmin`) VALUES (:name,:email,:password,:created_at,:lunas,:verified, :isAdmin)";

  try {
    $db = $this->get('db');

    $stmt = $db->prepare($sql);
    $stmt->execute([
      ':name' => $name,
      ':email' => $email,
      ':password' => $password,
      ':created_at' => $created_at,
      ':lunas' => $lunas,
      ':verified' => $verified,
      ':isAdmin' => $isAdmin
    ]);

    $user_id = $db->lastInsertId();

    if($lunas == 1) {
      $delcouponsql = "DELETE FROM coupons WHERE coupon = :coupon";
      $stmt = $db->prepare($delcouponsql);
      $stmt->execute([
        ':coupon' => $request->getParam('coupon')
      ]);
    }

    $settings = $this->get('settings'); // get settings array.
    $token = JWT::encode([
      'id' => $user_id,
      'name' => $name,
      'email' => $email,
      'isAdmin' => $isAdmin
    ], $settings['jwt']['secret'], "HS256");
    
    return $this->response->withJson(['token' => $token]);
  }
  catch (PDOException $e) {
    $msg = $e->getMessage();
    if (strpos($e->getMessage(), 'Duplicate entry') !== FALSE) {
      $msg = "User already exists";
    }
    $error = ['error' => ['text' => $msg]];
    return $response->withJson($error);
  }

});

// User reset
$app->post('/reset', function(Request $request, Response $response, array $args) {

  $email = $request->getParam('email');

  if (filter_var($email, FILTER_VALIDATE_EMAIL) === FALSE) {
    $error = ['error' => ['text' => 'Invalid email address']];
    return $response->withJson($error);
  }
  
  try {
    $token = md5(uniqid(rand(),true));

    $to      = $email;
    $subject = 'Password Reset';
    $message = "<p>Someone requested that the password be reset.</p>
    <p>If this was a mistake, just ignore this email and nothing will happen.</p>
    <p>To reset your password, visit the following address: <a href=\"http://kaderisasi.tec.itb.ac.id/reset/$token\">http://kaderisasi.tec.itb.ac.id/reset/$token</a></p>";
    $headers = 'From: admin@kader.tec.itb.ac.id' . "\r\n" .
            'To: ' . $email . "\r\n" .
            'Reply-To: admin@kader.tec.itb.ac.id' . "\r\n" .
            'X-Mailer: PHP/' . phpversion();

    if(mail($to, $subject, $message, $headers)) {
      $db = $this->get('db');
      $stmt = $db->prepare("SELECT id, email FROM users WHERE email = :email");
      $stmt->execute([
        ':email' => $email
      ]);

      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if(empty($row['email'])){
        $error = ['error' => ['text' => 'User not found']];
        return $response->withJson($error);
      }

      $id = $row['id'];

      $stmt = $db->prepare("INSERT INTO `user_reset`(`user_id`, `resetToken`) VALUES (:id,:token)");
      $stmt->execute(array(
          ':id' => $id,
          ':token' => $token
      ));

      $result = ["notice"=>["type"=>"success", "text" => "Check email to reset password"]];
    }
    else {
      $result = ['error' => ['text' => 'Something gone wrong']];
    }
    return $response->withJson($result);
  }
  catch (PDOException $e) {
    $msg = $e->getMessage();
    if (strpos($msg, "Duplicate entry") !== FALSE) {
      $msg = "Reset token already exists";
    }
    $error = ['error' => ['text' => $e->getMessage()]];
    return $response->withJson($error);
  }
});

$app->get('/reset/{token}', function(Request $request, Response $response, array $args) {
  $token = $args["token"];
  $sql = "SELECT `user_id`, `resetToken` as `reset_token` FROM `user_reset` WHERE resetToken = :token";
  try {
    $db = $this->get('db');
    $stmt = $db->prepare($sql);
    $stmt->execute([
      ':token' => $token
    ]);
    $result = $stmt->fetch(PDO::FETCH_OBJ);

    return $response->withJson($result);
  }
  catch (PDOException $e) {
    $error = ['error' => ['text' => $e->getMessage()]];
    return $response->withJson($error);
  }
});

$app->put('/reset/{token}', function(Request $request, Response $response, array $args) {
  $user_id = $request->getParam("user_id");
  $token = $request->getParam("reset_token");
  $new_password = $request->getParam("new_password");
  $sql = "SELECT `user_id` FROM `user_reset` WHERE resetToken = :token AND user_id = :uid";
  try {
    $db = $this->get('db');
    $stmt = $db->prepare($sql);
    $stmt->execute([
      ':token' => $token,
      ':uid' => $user_id
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if(empty($row['user_id'])){
      $error = ['error' => ['text' => 'Forbidden']];
      return $response->withJson($error);
    }

    $password = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE `users` SET password = :password WHERE id = :uid");
    $stmt->execute([
      ':password' => $password,
      ':uid' => $user_id
    ]);

    $result = ["notice"=>["type"=>"success", "text" => "Password reset success"]];
    return $response->withJson($result);
  }
  catch (PDOException $e) {
    $error = ['error' => ['text' => $e->getMessage()]];
    return $response->withJson($error);
  }
});