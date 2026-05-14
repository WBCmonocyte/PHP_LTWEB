<?php
// ============================================================================
// order_form.php - Trang form THÊM hoặc SỬA 1 đơn hàng (purchase_order)
// cùng với danh sách vật tư đi kèm (order_details).
//
// Cùng 1 file dùng cho 2 chế độ:
//   * Thêm mới: vào ?id rỗng (hoặc không có id)
//   * Sửa:      vào ?id=<order_id>
//
// Quy trình:
//   1. Nếu là Sửa: load đơn cũ + chi tiết vật tư vào mảng $form để hiển thị.
//   2. Nếu là POST: validate dữ liệu submit, insert/update DB trong transaction.
//   3. Render lại form (in dữ liệu $form, $errors, $suppliers, $materials).
// ============================================================================

session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

restore_remembered_login($conn);
require_login();

// ---- 1. Xác định chế độ thêm mới hay sửa ----
// Có ?id=... trong URL → chế độ sửa; ngược lại → thêm mới.
$orderId = isset($_GET["id"]) ? (int) $_GET["id"] : 0;
$isEdit = $orderId > 0;

// ---- 2. Lấy dữ liệu master cho dropdown ----
// Tải toàn bộ nhà cung cấp và vật tư để hiển thị trong select boxes.
// fetch_all() là helper trả về mảng nhiều dòng.
$suppliers = fetch_all($conn, "SELECT supplier_id, supplier_name, status FROM suppliers ORDER BY supplier_name ASC");
$materials = fetch_all($conn, "SELECT material_id, material_name, unit FROM materials ORDER BY material_name ASC");

// Whitelist các giá trị status hợp lệ — dùng cho validate.
$validStatuses = ["Pending", "Completed", "Cancelled"];

// $form: chứa toàn bộ state hiện tại của form (giá trị các input). Khi POST
// fail validate, ta ghi đè bằng input user vừa nhập để giữ form không bị reset.
// "items" là mảng các dòng vật tư trong bảng chi tiết.
$form = [
    "supplier_id" => "",
    // Mặc định ngày đặt = thời điểm hiện tại (chuẩn datetime-local "YYYY-MM-DDTHH:MM").
    "order_date" => date("Y-m-d\TH:i"),
    "expected_date" => "",
    "actual_date" => "",
    "order_status" => "Pending",
    "items" => [],
];

// $errors: dict các lỗi validate. Key đặc biệt:
//   "_general"  → lỗi chung (exception lúc lưu DB)
//   "items"     → lỗi tổng cho mục vật tư (ví dụ chưa có dòng nào)
//   "items_N"   → lỗi của dòng vật tư thứ N
//   các key khác = tên field
$errors = [];

// ---- 3. Nếu là Sửa: load đơn cũ từ DB vào $form ----
if ($isEdit) {
    // 3a. Lấy thông tin chính của đơn.
    $stmt = $conn->prepare("
        SELECT order_id, supplier_id, admin_id, order_date, expected_date, actual_date, total_amount, order_status
        FROM purchase_orders
        WHERE order_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Đơn không tồn tại → flash error và quay lại danh sách.
    if (!$existing) {
        flash_set("error", "Không tìm thấy đơn hàng #" . $orderId);
        redirect("orders.php");
    }

    // Đổ dữ liệu vào $form. Ép (string) cho các giá trị id để code so sánh
    // bằng "===" với value của <option> (cũng là string) hoạt động đúng.
    $form["supplier_id"] = (string) $existing["supplier_id"];
    // datetime_to_input(): "2024-01-15 09:30:00" → "2024-01-15T09:30" cho <input type=datetime-local>.
    $form["order_date"] = datetime_to_input($existing["order_date"]);
    $form["expected_date"] = datetime_to_input($existing["expected_date"]);
    $form["actual_date"] = datetime_to_input($existing["actual_date"]);
    $form["order_status"] = $existing["order_status"];

    // 3b. Lấy chi tiết vật tư của đơn.
    $stmt = $conn->prepare("
        SELECT od.material_id, od.quantity, od.unit_price
        FROM order_details od
        WHERE od.order_id = ?
        ORDER BY od.material_id ASC
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $detailRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Convert mỗi dòng vật tư từ format DB sang format cho form (toàn string).
    // format_decimal(): "120000.0000" → "120000" để hiển thị gọn trong input.
    foreach ($detailRows as $row) {
        $form["items"][] = [
            "material_id" => (string) $row["material_id"],
            "quantity" => (string) $row["quantity"],
            "unit_price" => format_decimal($row["unit_price"]),
        ];
    }
}

// ---- 4. Xử lý khi user submit form (POST) ----
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // 4a. Đọc các field cơ bản từ $_POST và ghi đè vào $form để giữ lại khi render lại.
    $form["supplier_id"] = trim($_POST["supplier_id"] ?? "");
    $form["order_date"] = trim($_POST["order_date"] ?? "");
    $form["expected_date"] = trim($_POST["expected_date"] ?? "");
    $form["actual_date"] = trim($_POST["actual_date"] ?? "");
    $form["order_status"] = trim($_POST["order_status"] ?? "Pending");

    // 4b. Đọc danh sách vật tư từ $_POST["items"].
    // HTML gửi mảng dạng:
    //   items[0][material_id], items[0][quantity], items[0][unit_price]
    //   items[1][material_id], ...
    // PHP tự convert thành mảng 2 chiều.
    $items = [];
    $rawItems = $_POST["items"] ?? [];

    if (is_array($rawItems)) {
        foreach ($rawItems as $rawItem) {
            // Phòng trường hợp ai đó nghịch ngợm submit kiểu không đúng.
            if (!is_array($rawItem)) {
                continue;
            }

            $materialId = trim((string) ($rawItem["material_id"] ?? ""));
            $quantity = trim((string) ($rawItem["quantity"] ?? ""));
            $unitPrice = trim((string) ($rawItem["unit_price"] ?? ""));

            // Bỏ qua dòng hoàn toàn rỗng (user thêm dòng mới rồi để trống).
            if ($materialId === "" && $quantity === "" && $unitPrice === "") {
                continue;
            }

            $items[] = [
                "material_id" => $materialId,
                "quantity" => $quantity,
                "unit_price" => $unitPrice,
            ];
        }
    }

    $form["items"] = $items;

    // 4c. Validate các field chính.
    // ctype_digit() đảm bảo chuỗi chỉ chứa các ký tự số "0".."9".
    if ($form["supplier_id"] === "" || !ctype_digit($form["supplier_id"])) {
        $errors["supplier_id"] = "Vui lòng chọn nhà cung cấp.";
    }

    if ($form["expected_date"] === "") {
        $errors["expected_date"] = "Vui lòng nhập ngày dự kiến giao.";
    }

    // Validate status nằm trong whitelist — chống user nghịch DOM gửi giá trị lạ.
    if (!in_array($form["order_status"], $validStatuses, true)) {
        $errors["order_status"] = "Trạng thái không hợp lệ.";
    }

    // 4d. Validate từng dòng vật tư & tính tổng tiền.
    if (count($items) === 0) {
        $errors["items"] = "Vui lòng thêm ít nhất một vật tư cho đơn hàng.";
    } else {
        // $seenMaterial: theo dõi material_id đã xuất hiện để chặn trùng dòng.
        $seenMaterial = [];
        $totalAmount = 0.0;

        foreach ($items as $index => $item) {
            // $lineNo: hiển thị 1-based cho user (dễ đọc hơn 0-based).
            $lineNo = $index + 1;

            // Mỗi điều kiện sai → set lỗi rồi continue để khỏi tính tiền sai.
            if ($item["material_id"] === "" || !ctype_digit($item["material_id"])) {
                $errors["items_$index"] = "Dòng $lineNo: chưa chọn vật tư.";
                continue;
            }

            if ($item["quantity"] === "" || !is_numeric($item["quantity"]) || (int) $item["quantity"] <= 0) {
                $errors["items_$index"] = "Dòng $lineNo: số lượng phải lớn hơn 0.";
                continue;
            }

            if ($item["unit_price"] === "" || !is_numeric($item["unit_price"]) || (float) $item["unit_price"] < 0) {
                $errors["items_$index"] = "Dòng $lineNo: đơn giá không hợp lệ.";
                continue;
            }

            $materialId = (int) $item["material_id"];

            // Chặn user chọn 2 dòng cùng 1 vật tư.
            if (isset($seenMaterial[$materialId])) {
                $errors["items_$index"] = "Dòng $lineNo: vật tư bị trùng với dòng khác.";
                continue;
            }

            // Đánh dấu đã thấy, cộng vào tổng tiền.
            $seenMaterial[$materialId] = true;
            $totalAmount += (int) $item["quantity"] * (float) $item["unit_price"];
        }
    }

    // 4e. Convert datetime từ format input sang format MySQL.
    // Nếu order_date rỗng → dùng thời điểm hiện tại làm mặc định.
    $orderDateDb = $form["order_date"] !== "" ? input_to_datetime($form["order_date"]) : date("Y-m-d H:i:s");
    $expectedDateDb = $form["expected_date"] !== "" ? input_to_datetime($form["expected_date"]) : null;
    $actualDateDb = $form["actual_date"] !== "" ? input_to_datetime($form["actual_date"]) : null;

    // Nếu user có nhập expected_date nhưng parse không ra → báo lỗi format.
    if ($form["expected_date"] !== "" && $expectedDateDb === null) {
        $errors["expected_date"] = "Ngày dự kiến giao không hợp lệ.";
    }

    // 4f. Lưu vào DB nếu KHÔNG có lỗi validate nào.
    if (empty($errors)) {
        // Bắt đầu transaction — đảm bảo nguyên tử: hoặc insert đơn + chi tiết
        // đều thành công, hoặc rollback hết để DB không bị trạng thái nửa vời.
        $conn->begin_transaction();

        try {
            if ($isEdit) {
                // UPDATE đơn cũ. Bảy tham số: i s s s d s i tương ứng:
                //   i: supplier_id (int)
                //   s: order_date (string datetime)
                //   s: expected_date
                //   s: actual_date (null vẫn truyền dưới dạng s)
                //   d: total_amount (double / decimal)
                //   s: order_status
                //   i: order_id (cho WHERE)
                $stmt = $conn->prepare("
                    UPDATE purchase_orders
                    SET supplier_id = ?, order_date = ?, expected_date = ?, actual_date = ?, total_amount = ?, order_status = ?
                    WHERE order_id = ?
                ");
                $supplierId = (int) $form["supplier_id"];
                $statusVal = $form["order_status"];
                // bind_param yêu cầu tham chiếu (by-reference) nên ta phải gán
                // các giá trị vào biến tạm trước thay vì dùng inline expression.
                $stmt->bind_param(
                    "isssdsi",
                    $supplierId,
                    $orderDateDb,
                    $expectedDateDb,
                    $actualDateDb,
                    $totalAmount,
                    $statusVal,
                    $orderId
                );
                $stmt->execute();
                $stmt->close();

                // Xóa toàn bộ chi tiết cũ — sẽ insert lại từ form (cách đơn giản
                // nhất khi chi tiết có thể thêm/sửa/xóa linh hoạt).
                $stmt = $conn->prepare("DELETE FROM order_details WHERE order_id = ?");
                $stmt->bind_param("i", $orderId);
                $stmt->execute();
                $stmt->close();
            } else {
                // INSERT đơn mới. admin_id lấy từ session = user đang thao tác.
                $stmt = $conn->prepare("
                    INSERT INTO purchase_orders
                        (supplier_id, admin_id, order_date, expected_date, actual_date, total_amount, order_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $supplierId = (int) $form["supplier_id"];
                $adminId = (int) $_SESSION["user_id"];
                $statusVal = $form["order_status"];
                $stmt->bind_param(
                    "iisssds",
                    $supplierId,
                    $adminId,
                    $orderDateDb,
                    $expectedDateDb,
                    $actualDateDb,
                    $totalAmount,
                    $statusVal
                );
                $stmt->execute();
                // insert_id: id mới sinh ra của AUTO_INCREMENT primary key.
                // Cần biến này để insert order_details bên dưới.
                $orderId = (int) $stmt->insert_id;
                $stmt->close();
            }

            // Insert toàn bộ dòng vật tư. Chuẩn bị 1 prepared statement rồi
            // execute nhiều lần với tham số khác nhau (hiệu quả hơn prepare lại).
            $stmt = $conn->prepare("
                INSERT INTO order_details (order_id, material_id, quantity, unit_price)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($items as $item) {
                $materialId = (int) $item["material_id"];
                $quantity = (int) $item["quantity"];
                $unitPrice = (float) $item["unit_price"];
                $stmt->bind_param("iiid", $orderId, $materialId, $quantity, $unitPrice);
                $stmt->execute();
            }

            $stmt->close();
            // Mọi thứ ổn → commit transaction để các thay đổi lưu chính thức.
            $conn->commit();

            // Flash + redirect → user thấy thông báo trên trang orders.php.
            flash_set("success", $isEdit ? "Đã cập nhật đơn #$orderId." : "Đã thêm đơn #$orderId.");
            redirect("orders.php");
        } catch (Throwable $ex) {
            // Bất kỳ lỗi nào trong khối try (SQL fail, exception,...) → rollback
            // để DB trở về trạng thái trước transaction. Throwable bắt cả
            // Exception lẫn Error (PHP 7+).
            $conn->rollback();
            $errors["_general"] = "Lưu thất bại: " . $ex->getMessage();
        }
    }
}

<?php // Tiêu đề và sidebar highlight cho phần render bên dưới. ?>
<?php // (Chế độ sửa hay thêm sẽ ảnh hưởng tới chữ tiêu đề.) ?>
<?php
$pageTitle = $isEdit ? "Sửa đơn #" . $orderId : "Thêm đơn hàng mới";
$active = "orders";
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - Supplier Center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <div class="layout">
        <?php require __DIR__ . "/partials/admin_sidebar.php"; ?>

        <main class="main">
            <section class="topbar">
                <div>
                    <h1><?php echo e($pageTitle); ?></h1>
                    <p>Nhập thông tin đơn hàng và danh sách vật tư đi kèm.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-outline" href="orders.php">&larr; Quay lại danh sách</a>
                </div>
            </section>

            <?php // Lỗi tổng (exception lúc lưu DB) — hiện trên cùng form. ?>
            <?php if (!empty($errors["_general"])): ?>
                <div class="alert alert-error"><?php echo e($errors["_general"]); ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <section class="panel">
                    <h2>Thông tin đơn hàng</h2>
                    <p class="panel-sub">Các trường có dấu <span class="req">*</span> là bắt buộc.</p>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="supplier_id">Nhà cung cấp <span class="req">*</span></label>
                            <select id="supplier_id" name="supplier_id" class="form-control" required>
                                <option value="">-- Chọn nhà cung cấp --</option>
                                <?php
                                // Render mỗi NCC thành 1 <option>. So sánh ép string với $form["supplier_id"]
                                // để hiển thị lại NCC đã chọn nếu validate fail (POST).
                                foreach ($suppliers as $sup):
                                ?>
                                    <option
                                        value="<?php echo (int) $sup["supplier_id"]; ?>"
                                        <?php echo ((string) $sup["supplier_id"] === $form["supplier_id"]) ? "selected" : ""; ?>
                                    >
                                        <?php echo e($sup["supplier_name"]); ?>
                                        <?php // Thêm hậu tố cho NCC bị inactive để admin biết. ?>
                                        <?php echo $sup["status"] === "Inactive" ? " (Tạm dừng)" : ""; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php // Hiện inline error ngay dưới field nếu có. ?>
                            <?php if (!empty($errors["supplier_id"])): ?>
                                <div class="form-error"><?php echo e($errors["supplier_id"]); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="order_status">Trạng thái <span class="req">*</span></label>
                            <select id="order_status" name="order_status" class="form-control" required>
                                <?php
                                // Chỉ cho phép 3 trạng thái trong whitelist $validStatuses.
                                foreach ($validStatuses as $st):
                                ?>
                                    <option value="<?php echo e($st); ?>" <?php echo $form["order_status"] === $st ? "selected" : ""; ?>>
                                        <?php echo e(status_label($st)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($errors["order_status"])): ?>
                                <div class="form-error"><?php echo e($errors["order_status"]); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="order_date">Ngày đặt</label>
                            <input
                                id="order_date"
                                name="order_date"
                                type="datetime-local"
                                class="form-control"
                                value="<?php echo e($form["order_date"]); ?>"
                            >
                            <span class="form-hint">Bỏ trống sẽ lấy thời gian hiện tại.</span>
                        </div>

                        <div class="form-group">
                            <label for="expected_date">Ngày dự kiến giao <span class="req">*</span></label>
                            <input
                                id="expected_date"
                                name="expected_date"
                                type="datetime-local"
                                class="form-control"
                                value="<?php echo e($form["expected_date"]); ?>"
                                required
                            >
                            <?php if (!empty($errors["expected_date"])): ?>
                                <div class="form-error"><?php echo e($errors["expected_date"]); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full">
                            <label for="actual_date">Ngày giao thực tế</label>
                            <input
                                id="actual_date"
                                name="actual_date"
                                type="datetime-local"
                                class="form-control"
                                value="<?php echo e($form["actual_date"]); ?>"
                            >
                            <span class="form-hint">Chỉ điền khi đơn đã được giao.</span>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Chi tiết vật tư</h2>
                            <p class="panel-sub">Thêm các dòng vật tư. Tổng tiền được tính tự động.</p>
                        </div>
                        <button type="button" class="btn btn-outline btn-sm" id="addItemBtn">+ Thêm vật tư</button>
                    </div>

                    <?php // Lỗi tổng cho phần items (ví dụ: chưa có dòng nào). ?>
                    <?php if (!empty($errors["items"])): ?>
                        <div class="alert alert-error"><?php echo e($errors["items"]); ?></div>
                    <?php endif; ?>

                    <?php
                    // Liệt kê các lỗi của từng dòng vật tư. Key có dạng "items_0", "items_1",...
                    // str_starts_with() là hàm PHP 8+: kiểm tra chuỗi bắt đầu bằng prefix.
                    foreach ($errors as $key => $msg):
                        if (str_starts_with($key, "items_")):
                    ?>
                            <div class="alert alert-error"><?php echo e($msg); ?></div>
                    <?php
                        endif;
                    endforeach;
                    ?>

                    <div class="table-wrap">
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th style="width:38%">Vật tư</th>
                                    <th style="width:8%">ĐVT</th>
                                    <th style="width:15%" class="numeric">Số lượng</th>
                                    <th style="width:18%" class="numeric">Đơn giá (đ)</th>
                                    <th style="width:16%" class="numeric">Thành tiền</th>
                                    <th style="width:5%"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody"></tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="numeric">Tổng cộng:</td>
                                    <td class="numeric"><span class="grand-total" id="grandTotal">0 đ</span></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <div class="form-actions">
                    <a class="btn btn-outline" href="orders.php">Hủy</a>
                    <button class="btn btn-primary" type="submit">
                        <?php // Đổi text nút theo chế độ thêm / sửa. ?>
                        <?php echo $isEdit ? "Cập nhật đơn" : "Tạo đơn hàng"; ?>
                    </button>
                </div>
            </form>
        </main>
    </div>

    <script>
        // Truyền dữ liệu từ PHP sang JS qua json_encode.
        // JSON_UNESCAPED_UNICODE: giữ nguyên ký tự tiếng Việt thay vì escape \uXXXX.
        const MATERIALS = <?php echo json_encode($materials, JSON_UNESCAPED_UNICODE); ?>;
        // Các dòng vật tư đã có sẵn (khi sửa) hoặc user vừa nhập (khi POST fail).
        const EXISTING_ITEMS = <?php echo json_encode($form["items"], JSON_UNESCAPED_UNICODE); ?>;
        const materialById = {};
        MATERIALS.forEach(m => { materialById[m.material_id] = m; });

        const itemsBody = document.getElementById("itemsBody");
        const grandTotalEl = document.getElementById("grandTotal");
        const addBtn = document.getElementById("addItemBtn");
        let rowIndex = 0;

        function formatCurrency(value) {
            const n = Number(value) || 0;
            return n.toLocaleString("vi-VN", { maximumFractionDigits: 0 }) + " đ";
        }

        function recalculateTotals() {
            let grand = 0;
            itemsBody.querySelectorAll("tr").forEach(tr => {
                const qty = Number(tr.querySelector(".qty-input").value) || 0;
                const price = Number(tr.querySelector(".price-input").value) || 0;
                const line = qty * price;
                tr.querySelector(".line-total").textContent = formatCurrency(line);
                grand += line;
            });
            grandTotalEl.textContent = formatCurrency(grand);
        }

        function updateUnit(tr) {
            const select = tr.querySelector(".material-select");
            const mat = materialById[select.value];
            tr.querySelector(".unit-cell").textContent = mat ? mat.unit : "-";
        }

        function buildMaterialOptions(selectedId) {
            let html = '<option value="">-- Chọn vật tư --</option>';
            MATERIALS.forEach(m => {
                const sel = String(m.material_id) === String(selectedId) ? " selected" : "";
                html += `<option value="${m.material_id}"${sel}>${m.material_name}</option>`;
            });
            return html;
        }

        function addRow(item) {
            const idx = rowIndex++;
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>
                    <select class="material-select" name="items[${idx}][material_id]" required>
                        ${buildMaterialOptions(item ? item.material_id : "")}
                    </select>
                </td>
                <td class="unit-cell">-</td>
                <td class="numeric">
                    <input class="qty-input" type="number" min="1" step="1" name="items[${idx}][quantity]" value="${item ? item.quantity : 1}" required>
                </td>
                <td class="numeric">
                    <input class="price-input" type="number" min="0" step="1000" name="items[${idx}][unit_price]" value="${item ? item.unit_price : 0}" required>
                </td>
                <td class="numeric"><span class="line-total">0 đ</span></td>
                <td class="numeric">
                    <button type="button" class="btn btn-ghost btn-icon remove-btn" title="Xóa">&times;</button>
                </td>
            `;
            itemsBody.appendChild(tr);

            tr.querySelector(".material-select").addEventListener("change", () => {
                updateUnit(tr);
                recalculateTotals();
            });
            tr.querySelector(".qty-input").addEventListener("input", recalculateTotals);
            tr.querySelector(".price-input").addEventListener("input", recalculateTotals);
            tr.querySelector(".remove-btn").addEventListener("click", () => {
                tr.remove();
                recalculateTotals();
            });

            updateUnit(tr);
            recalculateTotals();
        }

        addBtn.addEventListener("click", () => addRow());

        if (EXISTING_ITEMS.length > 0) {
            EXISTING_ITEMS.forEach(item => addRow(item));
        } else {
            addRow();
        }
    </script>
</body>
</html>
