<!DOCTYPE html>
<html lang="en">
<head>
	<link rel="stylesheet"
		href="{{ asset('laragram.css') }}">
	<title>LaraGram</title>
</head>
<body>

<!-- Our Header section Starts from here -->
	<header>
		<nav class="navbar">
			<div class="container">
				<div class="logo">
					<a href="#">
					<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609090809/download-200x200.png"
						alt="img1" height="30px">
					</a>
				</div>
				<div class="searchbar">
					<input type="text"
						placeholder="Search">
					<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093658/search-200x200.png"
						height="18"
						alt="img2">
				</div>
				<div class="nav-links">
					<ul class="nav-group">
						<li class="nav-item">
							<a href="#">
								<i class="fas fa-home"></i>
							</a>
						</li>
						<li class="nav-item">
							<a href="">
								<i class="fab fa-facebook-messenger"></i>
							</a>
						</li>
						<li class="nav-item">
							<a href="">
								<i class="far fa-compass"></i>
							</a>
						</li>
						<li class="nav-item">
							<a href="">
								<i class="far fa-heart"></i>
							</a>
						</li>
						<li class="nav-item">
							<div class="action">
								<div class="profile"
									onclick="menuToggle()">
									<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093221/g2-200x200.jpg"
										alt="user Avatar">
								</div>
							</div>
						</li>
					</ul>
				</div>
			</div>
		</nav>
	</header>

	<!-- Code for Showing the Status -->
	<main>
		<div class="container">
			<div class="col-9">
				<div class="statuses">
					<div class="status">
						<div class="image">
							<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220604085434/GeeksForGeeks-300x243.png"
								alt="img3">
						</div>
					</div>
					<div class="status">
						<div class="image">
							<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093221/g2-200x200.jpg"
								alt="img4">
						</div>
					</div>
					<div class="status">
						<div class="image">
							<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093241/g3-200x200.png"
								alt="img5">
						</div>
					</div>
					<div class="status">
						<div class="image">
							<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093229/g-200x200.png"
								alt="img6">
						</div>
					</div>
					<div class="status">
						<div class="image">
							<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093221/g2-200x200.jpg"
								alt="img7">
						</div>
					</div>
					<div class="status">
						<div class="image">
							<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220604085434/GeeksForGeeks-300x243.png"
								alt="img8">
						</div>
					</div>
				</div>
			
<!-- BEGIN IMAGENS -->
 
<livewire:imagem-list />
<!-- END IMAGENS -->
			</div>
			<div class="col-3">
				<div class="card">
					<h4>Suggestions For You</h4>
					<div class="top">
						<div class="userDetails">
							<div class="profilepic">
								<div class="profile_img">
									<div class="image">
										<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093221/g2-200x200.jpg"
											alt="img12">
									</div>
								</div>
							</div>
							<h3>Aditya Verma<br>
							<span>Follows You</span>
							</h3>
						</div>
						<div>
							<a href="#"
							class="follow">follow
							</a>
						</div>
					</div>
					<div class="top">
						<div class="userDetails">
							<div class="profilepic">
								<div class="profile_img">
									<div class="image">
										<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093229/g-200x200.png"
											alt="img13">
									</div>
								</div>
							</div>
							<h3>Amit Singh<br>
							<span>Follows You</span>
						</h3>
						</div>
						<div>
							<a href="#"
							class="follow">follow
						</a>
						</div>
					</div>
					<div class="top">
						<div class="userDetails">
							<div class="profilepic">
								<div class="profile_img">
									<div class="image">
										<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093221/g2-200x200.jpg"
											alt="img14">
									</div>
								</div>
							</div>
							<h3>Piyush Agarwal<br>
								<span>Followed by Keshav Agarwal</span>
							</h3>
						</div>
						<div>
							<a href="#"
							class="follow">follow</a>
						</div>
					</div>
					<div class="top">
						<div class="userDetails">
							<div class="profilepic">
								<div class="profile_img">
									<div class="image">
										<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093229/g-200x200.png"
											alt="img15">
									</div>
								</div>
							</div>
							<h3>Amit Sharma<br>
							<span>Follows You</span>
							</h3>
						</div>
						<div>
							<a href="#"
							class="follow">follow
						</a>
						</div>
					</div>
					<div class="top">
						<div class="userDetails">
							<div class="profilepic">
								<div class="profile_img">
									<div class="image">
										<img src=
"https://media.geeksforgeeks.org/wp-content/uploads/20220609093241/g3-200x200.png"
											alt="img16"
											class="cover">
									</div>
								</div>
							</div>
							<h3>Raj Goel<br>
								<span>Followed by Keshav Agarwal</span>
							</h3>
						</div>
						<div>
							<a href="#"
							class="follow">follow
							</a>
						</div>
					</div>
				</div>
			
			<!-- Our Footer Section will start from Here -->
				<div class="footer">
					<a class="footer-section" href="#">About</a>
					<a class="footer-section" href="#">Help</a>
					<a class="footer-section" href="#">API</a>
					<a class="footer-section" href="#">Jobs</a>
					<a class="footer-section" href="#">Privacy</a>
					<a class="footer-section" href="#">Terms</a>
					<a class="footer-section" href="#">Locations</a>
					<br>
					<a class="footer-section" href="#">Top Accounts</a>
					<a class="footer-section" href="#">Hashtag</a>
					<a class="footer-section" href="#">Language</a>
					<br><br>
					<span class="footer-section">
						© 2023 INSTAGRAM FROM FACEBOOK
					</span>
				</div>
			</div>
		</div>
	</main>
</body>
</html>