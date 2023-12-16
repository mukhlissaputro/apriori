<?php
$server="localhost";
$username="root";
$password="";
$database="uasmetlit";

$connection = mysqli_connect($server, $username, $password, $database);
if (mysqli_connect_errno())
{
    echo "Database connection failed.";
}

if(isset($_POST['btn_apriori'])){
    $minSupport = $_POST['support'];
    $minConfidence = $_POST['confidence'];
    $minLift = $_POST['lift'];
    $panjang_itemset = $_POST['jumlah_itemset']; // Panjang itemset yang diinginkan (bisa disesuaikan)
}else{
    $minSupport = 0.5;
    $minConfidence = 0.5;
    $minLift = 0.5;
    $panjang_itemset = 2;
}
$data_penjualan = [];
$data_produk = [];

/* 1. Membuat terlebih dahulu data set menjadi sebuah array yang berstruktur:*/

$query_data_item = mysqli_query($connection,"SELECT Transaction_ID, Product, Total_Items, City, Promotion, Payment_Method FROM `datacleansing` WHERE `City` LIKE '%Los Angeles%' AND `Promotion` LIKE '%None%' AND `Discount_Applied` LIKE '%False%' AND `Season` = 'Summer'") or die ('GAGAL');
// AND `Discount_Applied` LIKE '%False%' AND `Date` LIKE '%2022-12%' AND `Promotion` LIKE '%Discount on Selected Items%'  AND `Date` LIKE '%2022-12%'
while($p = mysqli_fetch_array($query_data_item)){

    $data = array("id" => $p['Transaction_ID'], "item" => $p['Product'], "total" => $p['Total_Items'], "promotion" => $p['Promotion'], "city" => $p['City'], "payment" => $p['Payment_Method']);

    // Pisahkan produk menjadi array menggunakan koma sebagai pemisah
    $productArray = explode(', ', $p['Product']);
    $total_items = $p['Total_Items'];
    
    if (count($productArray) >= $panjang_itemset) {     // Jika jumlah produk dalam satu transaksi lebih dari 2 dst maka.. 
        if ($total_items == count($productArray) ) {     // Jika jumlah Total_Items lebih besar dari atau sama dengan jumlah produk maka.. 
            array_push($data_penjualan, $data);
        }
    }

}

/* 2. Memecah setiap produk dalam satu penjualan pada dataset, kemudian mencari berapa kali produk tersebut muncul pada dataset  */
for ($i = 0; $i < count($data_penjualan); $i++) {
    $ar = [];
    $val = explode(",", $data_penjualan[$i]["item"]);
    for ($j = 0; $j < count($val); $j++) {
        $ar[] = ltrim($val[$j]);
    }
    array_push($data_produk, $ar);
}

/* 3. Mengecek dan menghitung jumlah produk tersebut berapa kali muncul dalam transaksi */

function frekuensiItem($data)
{
    $data_produk = [];
    for ($i = 0; $i < count($data); $i++) {
        $jum = array_count_values($data[$i]);
        foreach ($jum as $key => $v) {
            if (array_key_exists($key, $data_produk)) {
                $data_produk[$key] += 1;
            } else {
                $data_produk[$key] = 1;
            }
        }
    }
    return $data_produk;
}


/* 4. Melakukan Pengecekan Support setiap produk */
function eliminasiItem($data, $data_penjualan, $minSupport)
{
    $data_produk = [];
    foreach ($data as $key => $v) {
        if (($v / count($data_penjualan)) >= $minSupport) {
            $data_produk[$key] = $v;
        }
    }
    return $data_produk;
}

/* 5. Pembentukan Itemset Kandidat: */
function getItemsetKandidat($data_produk, $panjang_itemset, $dataEliminasi)
{
    $itemset_kandidat = [];
    $jumlah_transaksi = count($data_produk);

    for ($i = 0; $i < $jumlah_transaksi; $i++) {
        $itemset = $data_produk[$i];
        
        // Hanya mempertimbangkan transaksi yang memiliki setidaknya satu produk dalam $dataEliminasi
        if (array_intersect($itemset, $dataEliminasi)) { //Jika ada transaksi yang mengandung produk yang dimaksud maka....
            $jumlah_produk = count($itemset); //Hitung produk pada transaksi itu

            for ($j = 0; $j < $jumlah_produk; $j++) { //Lakukan perulangan sesuai dengan jumlah produk yang ada pada transaksi itu
                $kandidat = [$itemset[$j]];

                for ($k = $j + 1; $k < $jumlah_produk; $k++) {
                    $kandidat[] = $itemset[$k];
                    sort($kandidat);

                    if (count($kandidat) == $panjang_itemset && !in_array($kandidat, $itemset_kandidat)) {
                        $itemset_kandidat[] = $kandidat;
                    }
                }
            }
        }
    }

    return $itemset_kandidat;
}


/* 6. Generasi Aturan Asosiasi: */
function generateAturanAsosiasi($itemset_kandidat, $data_produk, $minConfidence, $data_produkeliminasi, $minLift)
{
    $aturan_asosiasi = [];

    foreach ($itemset_kandidat as $itemset) {
        $jumlah_itemset = count($itemset);

        for ($i = 1; $i < $jumlah_itemset; $i++) {
            $left_side = array_slice($itemset, 0, $i);
            $right_side = array_slice($itemset, $i);

            // Hanya mempertimbangkan itemset yang memiliki setidaknya satu produk dalam $data_produkeliminasi
            if (count(array_intersect($left_side, $data_produkeliminasi)) > 0) {
                $support_itemset = countItemset($itemset, $data_produk, $data_produkeliminasi);
                $support_left_side = countItemset($left_side, $data_produk, $data_produkeliminasi);
                $support_right_side = countItemset($right_side, $data_produk, $data_produkeliminasi);

                //Menghitung Nilai Confidence
                $confidence = $support_itemset / $support_left_side;
                //Menghitung Nilai Lift Untuk Lebih meyakinkan
                $lift = $support_itemset / ($support_left_side * $support_right_side);                
                if ($confidence >= $minConfidence) {
                    $aturan_asosiasi[] = [
                        'left_side' => $left_side,
                        'right_side' => $right_side,
                        'support_itemset' => $support_itemset,
                        'support_leftside' => $support_left_side,
                        'confidence' => $confidence,
                        'support_rightside' => $support_right_side,
                        'lift' => $lift,
                    ];

                    //if ($lift >= $minLift) {//}
                }
            }
        }
    }

    return $aturan_asosiasi;
}

/* Fungsi bantu untuk menghitung jumlah munculnya itemset dalam dataset: */
function countItemset($itemset, $data_produk, $dataEliminasi)
{
    $count = 0;

    foreach ($data_produk as $transaksi) {
        // Hanya mempertimbangkan transaksi yang memiliki setidaknya satu produk dalam $dataEliminasi
        if (array_intersect($transaksi, $dataEliminasi)) {
            if (array_diff($itemset, $transaksi) === []) {
                $count++;
            }
        }
    }

    return $count;
}

?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.css" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
        <script src="https://code.jquery.com/jquery-3.7.1.js" integrity="sha256-eKhayi8LEQwp4NKxN+CfCh+3qOVUtJn3QNZ0TciWLP4=" crossorigin="anonymous"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.js"></script>
        <script src="https://polyfill.io/v3/polyfill.min.js?features=es6"></script>
        <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
        <script type="text/javascript">
            $(document).ready( function () {
                $('#tabel_dataset').DataTable();
                $('#tabel_frekuensi').DataTable();
                $('#tabel_eliminasi').DataTable();
                $('#tabel_confidence').DataTable();
                $('#tabel_lift').DataTable();
            });
        </script>
    </head>
    <body style="color:black !important">
        <div style="background-color: #e5e5f7;opacity: 0.8;background-image:  linear-gradient(#444cf7 1px, transparent 1px), linear-gradient(to right, #444cf7 1px, #e5e5f7 1px);background-size: 20px 20px;">
            <div class="container" style="background: white">
                <header class="d-flex flex-wrap justify-content-center py-3 mb-4 border-bottom">
                <a href="/" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto link-body-emphasis text-decoration-none">
                    <svg class="bi me-2" width="40" height="32"><use xlink:href="#bootstrap"></use></svg>
                    <span class="fs-4">Algoritma Apriori 23223063 - Mukhlis Saputro</span>
                </a>
                </header>
            </div>
            <div class="col-md-8" style="margin: 10px auto; background:white;padding:2em;border-radius:5px;">        
                <form action="" method="post">
                    <h5 class="mb-3 font-weight-normal" style="margin-bottom:0.5em;">DATASET</h5>
                    <table id="tabel_dataset" class="table table-bordered">
                        <thead class="text-center">
                            <tr>
                                <th>No</th>
                                <th>Nama Produk</th>
                                <th>Total</th>
                                <th>Promotion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            for ($i = 0; $i < count($data_penjualan); $i++) {
                                echo ("<tr>");
                                echo ("<td class='text-center'>" . $data_penjualan[$i]["id"] . "</td>");
                                echo ("<td>" . $data_penjualan[$i]["item"] . "</td>");
                                echo ("<td>" . $data_penjualan[$i]["total"] . "</td>");
                                echo ("<td>" . $data_penjualan[$i]["promotion"] . "</td>");
                                echo ("</tr>");
                            }
                            ?>
                        </tbody>
                    </table>
                    <div class="d-flex gap-2 justify-content-center py-5">
                        <table style="text-align: left; width: 100%;">
                            <tr>
                                <td width="13%">
                                    <label for="jumlah_itemset">Jumlah Itemset</label>
                                </td>
                                <td width="2%">:</td>
                                <td width="25%">
                                    <input type="number" name="jumlah_itemset" id="jumlah_itemset" min="2" required>
                                </td>
                                <td width="13%">
                                    <label for="suppport">Support</label>
                                </td>
                                <td width="2%">:</td>
                                <td width="25%">
                                    <input type="number" step="0.1" max = "1" name="support" id="support" required>
                                </td>
                                <td rowspan="2" width="20%">
                                    <button id="btn_apriori" name="btn_apriori" class="btn btn-primary d-inline-flex align-items-center" type="submit">
                                        Proses Apriori
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td width="13%">
                                    <label for="confidence">Confidence</label>
                                </td>
                                <td width="2%">:</td>
                                <td width="25%">
                                    <input type="number" step="0.1" max = "1" name="confidence" id="confidence" required>
                                </td>
                                <td width="13%">
                                    <label for="lift">Lift</label>
                                </td>
                                <td width="2%">:</td>
                                <td width="25%">
                                    <input type="number" step="0.1" max = "1" name="lift" id="lift" required>
                                </td>
                            </tr>
                        </table>
                    </div>
                </form>
            </div>
                
            <?php if (isset($_POST['btn_apriori'])) {  ?>
            <div class="col-md-8" style="margin: 10px auto; background:white;padding:2em;border-radius:5px;">    
                <h5 class="mb-3 font-weight-normal" style="margin-bottom:0.5em;">1. HITUNG DATA FREKUENSI PENJUALAN SETIAP PRODUK</h5>
                <hr>
                <table id="tabel_frekuensi" class="table table-bordered table-earning">
                    <thead>
                        <tr>
                            <th>Nama Produk</th>
                            <th>Frekuensi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $frekuensi_item = frekuensiItem($data_produk);
                            foreach ($frekuensi_item as $key => $val) {
                                echo ("<tr>");
                                echo ("<td>" . $key . "</td>");
                                echo ("<td>" . $val . "</td>");
                                echo ("</tr>");
                             }
                        ?>
                    <tbody>
                </table>
                </hr>
            </div>

            <div class="col-md-8" style="margin: 10px auto; background:white;padding:2em;border-radius:5px;">    
                <h5 class="mb-3 font-weight-normal" style="margin-bottom:0.5em;">2. ELIMINASI PRODUK YANG MEMILIKI FREKUENSI DIBAWAH SUPPORT</h5>
                <hr>
                <p>
                    Minimum item frequency didefinisikan sebagai penyaring itemset yang kurang relevan atau kurang signifikan untuk di analisis lebih lanjut (support).
                    Secara matematis, rumus mengeliminasi produk dibawah frekuensi adalah:
                    \[Support (S) = {n \over N}.\]
                </p>
                <table id="tabel_eliminasi" class="table table-bordered table-earning">
                    <thead>
                        <tr>
                            <th>Nama Produk</th>
                            <th>Frekuensi</th>
                            <th>Minimum Support</th>
                            <th>Nilai Variabel</th>
                            <th>Hasil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $data_produkeliminasi = [];
                            $dataEliminasi = eliminasiItem($frekuensi_item, $data_penjualan, $minSupport);
                                foreach ($dataEliminasi as $key => $val) {
                                    echo ("<tr>");
                                    echo ("<td>" . $key . "</td>");
                                    echo ("<td>" . $val . "</td>");
                                    echo ("<td>" . $minSupport . "</td>");
                                    echo ("<td>" . $val . " / " . count($data_penjualan) ."</td>");
                                    echo ("<td>" . $val / count($data_penjualan) ."</td>");
                                    echo ("</tr>");
                                    $data_produkeliminasi[] = $key;
                            }
                            ?>
                    <tbody>
                </table>
                </hr>
            </div>

            

            <div class="col-md-8" style="margin: 10px auto; background:white;padding:2em;border-radius:5px;">    
                <h5 class="mb-3 font-weight-normal" style="margin-bottom:0.5em;">3. HITUNG NILAI CONFIDENCE</h5>
                <hr>
                <p>
                    Confidence didefinisikan sebagai rasio dari support itemset yang terdiri dari union dari A dan B terhadap 
                    support itemset yang hanya terdiri dari A. Secara matematis, rumus confidence untuk aturan asosiasi "A => B" adalah:
                    \[Confidence (A => B) = {Support (AUB) \over Support (A)}.\]
                </p>
                <table id="tabel_confidence" class="table table-bordered table-earning">
                    <thead>
                        <tr>
                            <th>Nama Produk</th>
                            <th>Nilai Variabel</th>
                            <th>Confidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            // Menjalankan fungsi untuk mendapatkan itemset kandidat
                            $itemset_kandidat = getItemsetKandidat($data_produk, $panjang_itemset, $data_produkeliminasi);

                            // Menjalankan fungsi untuk menghasilkan aturan asosiasi
                            $hasil_aturan_asosiasi = generateAturanAsosiasi($itemset_kandidat, $data_produk, $minConfidence, $data_produkeliminasi, $minLift);
                            
                            foreach ($hasil_aturan_asosiasi as $aturan) {
                                echo "<tr>";
                                echo "<td> Jika membeli " . implode(', ', $aturan['left_side']) . " maka juga membeli " . implode(', ', $aturan['right_side']) . "</td>";
                                echo "<td>" . $aturan['support_itemset'] . " / ". $aturan['support_leftside'] ."</td>";
                                echo "<td>" . $aturan['confidence'] . "</td>";
                                echo "</tr>";
                            }
                            ?>
                    <tbody>
                </table>
                </hr>
            </div>

            <div class="col-md-8" style="margin: 10px auto; background:white;padding:2em;border-radius:5px;">    
                <h5 class="mb-3 font-weight-normal" style="margin-bottom:0.5em;">4. NILAI LIFT</h5>
                <hr>
                <p>
                Lift didefinisikan untuk mengukur seberapa besar peningkatan peluang pembelian suatu item atau itemset 
                ketika item atau itemset lain dibeli. Rumus lift untuk aturan asosiasi "A => B" adalah sebagai berikut:
                    \[Lift (A => B) = {Support (AUB) \over Support (A) x Support (B)}.\]
                
                <br>-  Jika Lift > 1 Maka indikasi positif bahwa A dan B memiliki hubungan yang lebih kuat daripada yang diharapkan secara acak. Ini menunjukkan bahwa kemungkinan A dan B dibeli bersama lebih tinggi daripada jika pembelian mereka bersifat independen.
                <br>-  Jika Lift = 1 Maka indikasi bahwa A dan B bersifat independen. Hubungan antara A dan B tidak lebih mungkin atau kurang mungkin daripada yang diharapkan secara acak.
                <br>-  Jika Lift < 1 Maka indikasi negatif bahwa A dan B memiliki hubungan yang lebih lemah daripada yang diharapkan secara acak. Ini menunjukkan bahwa kemungkinan A dan B dibeli bersama lebih rendah daripada jika pembelian mereka bersifat independen.
                </p>
                <table id="tabel_lift" class="table table-bordered table-earning">
                    <thead>
                        <tr>
                            <th>Nama Produk</th>
                            <th>Nilai Variabel</th>
                            <th>Lift</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php                            
                            foreach ($hasil_aturan_asosiasi as $aturan) {
                                echo "<tr>";
                                echo "<td> Jika membeli " . implode(', ', $aturan['left_side']) . " maka juga membeli " . implode(', ', $aturan['right_side']) . "</td>";
                                echo "<td>" . $aturan['support_itemset'] . " / (". $aturan['support_leftside'] . " x " . $aturan['support_rightside'] .")</td>";
                                echo "<td>" . $aturan['lift'] . "</td>";
                                echo "</tr>";
                            }
                            ?>
                    <tbody>
                </table>
                </hr>
            </div>
            <?php } ?>
        </div>
    </body>
</html>
