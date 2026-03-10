<!doctype html>
<html lang="en">

<?php include('includes/head.php')?>


<body data-sidebar="dark">

<!-- Loader -->
<?php include('includes/pre-loader.php')?>

<!-- Begin page -->
<div id="layout-wrapper">

<?php include('includes/topbar.php')?>    

    <!-- ========== Left Sidebar Start ========== -->
    <div class="vertical-menu">

        <div data-simplebar class="h-100">

            <!--- Sidemenu -->
            <?php include('includes/sidebar.php')?>
            <!-- Sidebar -->
        </div>
    </div>
    <!-- Left Sidebar End -->

    <!-- ============================================================== -->
    <!-- Start right Content here -->
    <!-- ============================================================== -->
    <div class="main-content">
        <div class="page-content">
           

            <div class="container-fluid">

                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-info mt-2">15,852</h3> Monthly Statistics
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-purple mt-2">9,514</h3> New Orders
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-primary mt-2">289</h3> New Users
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="mb-2 card-body text-muted">
                                <h3 class="text-danger mt-2">5,220</h3> Unique Visitors
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end row -->

                <div class="row">

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Monthly Earnings</h4>

                                <div class="row text-center mt-4">
                                    <div class="col-6">
                                        <h5 class="mb-2 font-size-18">56241</h5>
                                        <p class="text-muted text-truncate">Marketplace</p>
                                    </div>
                                    <div class="col-6">
                                        <h5 class="mb-2 font-size-18">23651</h5>
                                        <p class="text-muted text-truncate">Total Income</p>
                                    </div>
                                </div>

                                <div id="morris-donut-example" class="morris-charts morris-chart-height" data-colors='["#ebeff2", "--bs-primary", "--bs-info"]'></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Email Sent</h4>

                                <div class="row text-center mt-4">
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18">56241</h5>
                                        <p class="text-muted text-truncate">Marketplace</p>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18">23651</h5>
                                        <p class="text-muted text-truncate">Total Income</p>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18">23651</h5>
                                        <p class="text-muted text-truncate">Total Month</p>
                                    </div>
                                </div>

                                <div id="morris-area-example" class="morris-charts morris-chart-height" data-colors='["rgb(200,200,200)", "--bs-primary", "--bs-info"]'></div>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- end row -->

                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Latest Transactions</h4>

                                <div class="table-responsive">
                                    <table class="table mt-4 mb-0 table-centered table-nowrap">

                                        <tbody>
                                            <tr>
                                                <td>
                                                    <img src="assets/images/users/avatar-2.jpg" alt="user-image"
                                                        class="avatar-sm rounded-circle me-2" /> Herbert C. Patton
                                                </td>
                                                <td><i class="mdi mdi-checkbox-blank-circle text-success"></i>
                                                    Confirm</td>
                                                <td>
                                                    $14,584
                                                    <p class="m-0 text-muted">Amount</p>
                                                </td>
                                                <td>
                                                    5/12/2016
                                                    <p class="m-0 text-muted">Date</p>
                                                </td>
                                                <td>
                                                    <button type="button"
                                                        class="btn btn-secondary btn-sm waves-effect">Edit</button>
                                                </td>
                                            </tr>

                                            <tr>
                                                <td>
                                                    <img src="assets/images/users/avatar-3.jpg" alt="user-image"
                                                        class="avatar-sm rounded-circle me-2" /> Mathias N. Klausen
                                                </td>
                                                <td><i class="mdi mdi-checkbox-blank-circle text-warning"></i>
                                                    Waiting payment</td>
                                                <td>
                                                    $8,541
                                                    <p class="m-0 text-muted">Amount</p>
                                                </td>
                                                <td>
                                                    10/11/2016
                                                    <p class="m-0 text-muted">Date</p>
                                                </td>
                                                <td>
                                                    <button type="button"
                                                        class="btn btn-secondary btn-sm waves-effect">Edit</button>
                                                </td>
                                            </tr>

                                            <tr>
                                                <td>
                                                    <img src="assets/images/users/avatar-4.jpg" alt="user-image"
                                                        class="avatar-sm rounded-circle me-2" /> Nikolaj S.
                                                    Henriksen
                                                </td>
                                                <td><i class="mdi mdi-checkbox-blank-circle text-success"></i>
                                                    Confirm</td>
                                                <td>
                                                    $954
                                                    <p class="m-0 text-muted">Amount</p>
                                                </td>
                                                <td>
                                                    8/11/2016
                                                    <p class="m-0 text-muted">Date</p>
                                                </td>
                                                <td>
                                                    <button type="button"
                                                        class="btn btn-secondary btn-sm waves-effect">Edit</button>
                                                </td>
                                            </tr>

                                            <tr>
                                                <td>
                                                    <img src="assets/images/users/avatar-5.jpg" alt="user-image"
                                                        class="avatar-sm rounded-circle me-2" /> Lasse C. Overgaard
                                                </td>
                                                <td><i class="mdi mdi-checkbox-blank-circle text-danger"></i>
                                                    Payment expired</td>
                                                <td>
                                                    $44,584
                                                    <p class="m-0 text-muted">Amount</p>
                                                </td>
                                                <td>
                                                    7/11/2016
                                                    <p class="m-0 text-muted">Date</p>
                                                </td>
                                                <td>
                                                    <button type="button"
                                                        class="btn btn-secondary btn-sm waves-effect">Edit</button>
                                                </td>
                                            </tr>

                                            <tr>
                                                <td>
                                                    <img src="assets/images/users/avatar-6.jpg" alt="user-image"
                                                        class="avatar-sm rounded-circle me-2" /> Kasper S. Jessen
                                                </td>
                                                <td><i class="mdi mdi-checkbox-blank-circle text-success"></i>
                                                    Confirm</td>
                                                <td>
                                                    $8,844
                                                    <p class="m-0 text-muted">Amount</p>
                                                </td>
                                                <td>
                                                    1/11/2016
                                                    <p class="m-0 text-muted">Date</p>
                                                </td>
                                                <td>
                                                    <button type="button"
                                                        class="btn btn-secondary btn-sm waves-effect">Edit</button>
                                                </td>
                                            </tr>

                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Recent Activity Feed</h4>

                                <ol class="activity-feed mb-0">
                                    <li class="feed-item">
                                        <span class="date">Sep 25</span>
                                        <span class="activity-text">Responded to need “Volunteer Activities”</span>
                                    </li>

                                    <li class="feed-item">
                                        <span class="date">Sep 24</span>
                                        <span class="activity-text">Added an interest “Volunteer Activities”</span>
                                    </li>
                                    <li class="feed-item">
                                        <span class="date">Sep 23</span>
                                        <span class="activity-text">Joined the group “Boardsmanship Forum”</span>
                                    </li>
                                    <li class="feed-item">
                                        <span class="date">Sep 21</span>
                                        <span class="activity-text">Responded to need “In-Kind Opportunity”</span>
                                    </li>
                                    <li class="feed-item">
                                        <span class="date">Sep 18</span>
                                        <span class="activity-text">Created need “Volunteer Activities”</span>
                                    </li>
                                    <li class="feed-item">
                                        <span class="date">Sep 17</span>
                                        <span class="activity-text">Attending the event “Some New Event”. Responded
                                            to needed.</span>
                                    </li>
                                    <li class="feed-item pb-0">
                                        <span class="activity-text">
                                            <a href="" class="text-primary">More Activities</a>
                                        </span>
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- end row -->

            </div>
            <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include('includes/footer.php')?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- Right Sidebar -->
<?php include('includes/rightbar.php')?>
<!-- /Right-bar -->

<!-- Right bar overlay-->


<!-- JAVASCRIPT -->
<?php include('includes/scripts.php')?>

</body>

</html>