<?php
session_start();
require "connect.php";
$page_title = "Users data";
require "includes/header.php";
require "includes/navbar2.php";
$stmt = $con->prepare("SELECT * FROM `users`");
$stmt->execute();
$data = $stmt->get_result();
if($data->num_rows> 0 ){
?>
<h3 align="center">Below is the data of every user</h3>
<hr>
<div class="table-responsive">
<table class="table table-bordered table-striped">
    <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Contact</th>
        <th>Email</th>
        <th>Verify Token</th>
        <th>Verify Status</th>
        <th>Created at</th>
        <th>Operation</th>
    </tr>
<?php
while($result = $data->fetch_assoc()){
?>
<tr>
<td><?=$result['id']?></td>
<td><?=$result['name']?></td>
<td><?=$result['phone']?></td>
<td><?=$result['email']?></td> 
<td><?=$result['verify_token']?></td>
<td><?=$result['verify_status']?></td>
<td><?=$result['created_at']?></td>
<td><form action="deleteuser.php" method="post" enctype="multipart/form-data">
    <input type="hidden" value="<?php echo $result['id']?>" name="id" >
    <input type="hidden" value="<?php echo $result['name']?>" name="name" >
    <input type="hidden" value="<?php echo $result['email']?>" name="email" >
    <button type="submit" name="delete">DELETE USER</button>
</form></td>
</tr>
<?php
}
}else{
 echo "
 <script>
    alert('No data found');
    window.location.href = 'adminpage.php';
</script>
 ";
}
?>
</table>
</div>

<?php
require "includes/footer.php";
?>
