<?php
session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

restore_remembered_login($conn);
require_login();

$orderId = isset($_GET["id"]) ? (int) $_GET["id"] : 0;
$isEdit = $orderId > 0;

$suppliers = fetch_all($conn, "SELECT supplier_id, supplier_name, status FROM suppliers ORDER BY supplier_name ASC");
$materials = fetch_all($conn, "SELECT material_id, material_name, unit FROM materials ORDER BY material_name ASC");

$validStatuses = ["Pending", "Completed", "Cancelled"];

$form = [
    "supplier_id" => "",
    "order_date" => date("Y-m-d\TH:i"),
    "expected_date" => "",
    "actual_date" => "",
    "order_status" => "Pending",
    "items" => [],
];

$errors = [];

if ($isEdit) {
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

    if (!$existing) {
        flash_set("error", "Không tìm thấy đơn hàng #" . $orderId);
        redirect("orders.php");
    }

    $form["supplier_id"] = (string) $existing["supplier_id"];
    $form["order_date"] = datetime_to_input($existing["order_date"]);
    $form["expected_date"] = datetime_to_input($existing["expected_date"]);
    $form["actual_date"] = datetime_to_input($existing["actual_date"]);
    $form["order_status"] = $existing["order_status"];

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

    foreach ($detailRows as $row) {
        $form["items"][] = [
            "material_id" => (string) $row["material_id"],
            "quantity" => (string) $row["quantity"],
            "unit_price" => rtrim(rtrim((string) $row["unit_price"], "0"), "."),
        ];
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form["supplier_id"] = trim($_POST["supplier_id"] ?? "");
    $form["order_date"] = trim($_POST["order_date"] ?? "");
    $form["expected_date"] = trim($_POST["expected_date"] ?? "");
    $form["actual_date"] = trim($_POST["actual_date"] ?? "");
    $form["order_status"] = trim($_POST["order_status"] ?? "Pending");

    $items = [];
    $rawItems = $_POST["items"] ?? [];

    if (is_array($rawItems)) {
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $materialId = trim((string) ($rawItem["material_id"] ?? ""));
            $quantity = trim((string) ($rawItem["quantity"] ?? ""));
            $unitPrice = trim((string) ($rawItem["unit_price"] ?? ""));

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

    if ($form["supplier_id"] === "" || !ctype_digit($form["supplier_id"])) {
        $errors["supplier_id"] = "Vui lòng chọn nhà cung cấp.";
    }

    if ($form["expected_date"] === "") {
        $errors["expected_date"] = "Vui lòng nhập ngày dự kiến giao.";
    }

    if (!in_array($form["order_status"], $validStatuses, true)) {
        $errors["order_status"] = "Trạng thái không hợp lệ.";
    }

    if (count($items) === 0) {
        $errors["items"] = "Vui lòng thêm ít nhất một vật tư cho đơn hàng.";
    } else {
        $seenMaterial = [];
        $totalAmount = 0.0;

        foreach ($items as $index => $item) {
            $lineNo = $index + 1;

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

            if (isset($seenMaterial[$materialId])) {
                $errors["items_$index"] = "Dòng $lineNo: vật tư bị trùng với dòng khác.";
                continue;
            }

            $seenMaterial[$materialId] = true;
            $totalAmount += (int) $item["quantity"] * (float) $item["unit_price"];
        }
    }

    $orderDateDb = $form["order_date"] !== "" ? input_to_datetime($form["order_date"]) : date("Y-m-d H:i:s");
    $expectedDateDb = $form["expected_date"] !== "" ? input_to_datetime($form["expected_date"]) : null;
    $actualDateDb = $form["actual_date"] !== "" ? input_to_datetime($form["actual_date"]) : null;

    if ($form["expected_date"] !== "" && $expectedDateDb === null) {
        $errors["expected_date"] = "Ngày dự kiến giao không hợp lệ.";
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            if ($isEdit) {
                $stmt = $conn->prepare("
                    UPDATE purchase_orders
                    SET supplier_id = ?, order_date = ?, expected_date = ?, actual_date = ?, total_amount = ?, order_status = ?
                    WHERE order_id = ?
                ");
                $supplierId = (int) $form["supplier_id"];
                $statusVal = $form["order_status"];
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

                $stmt = $conn->prepare("DELETE FROM order_details WHERE order_id = ?");
                $stmt->bind_param("i", $orderId);
                $stmt->execute();
                $stmt->close();
            } else {
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
                $orderId = (int) $stmt->insert_id;
                $stmt->close();
            }

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
            $conn->commit();

            flash_set("success", $isEdit ? "Đã cập nhật đơn #$orderId." : "Đã thêm đơn #$orderId.");
            redirect("orders.php");
        } catch (Throwable $ex) {
            $conn->rollback();
            $errors["_general"] = "Lưu thất bại: " . $ex->getMessage();
        }
    }
}

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
                                <?php foreach ($suppliers as $sup): ?>
                                    <option
                                        value="<?php echo (int) $sup["supplier_id"]; ?>"
                                        <?php echo ((string) $sup["supplier_id"] === $form["supplier_id"]) ? "selected" : ""; ?>
                                    >
                                        <?php echo e($sup["supplier_name"]); ?>
                                        <?php echo $sup["status"] === "Inactive" ? " (Tạm dừng)" : ""; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($errors["supplier_id"])): ?>
                                <div class="form-error"><?php echo e($errors["supplier_id"]); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="order_status">Trạng thái <span class="req">*</span></label>
                            <select id="order_status" name="order_status" class="form-control" required>
                                <?php foreach ($validStatuses as $st): ?>
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
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:8px;">
                        <div>
                            <h2>Chi tiết vật tư</h2>
                            <p class="panel-sub">Thêm các dòng vật tư. Tổng tiền được tính tự động.</p>
                        </div>
                        <button type="button" class="btn btn-outline btn-sm" id="addItemBtn">+ Thêm vật tư</button>
                    </div>

                    <?php if (!empty($errors["items"])): ?>
                        <div class="alert alert-error"><?php echo e($errors["items"]); ?></div>
                    <?php endif; ?>

                    <?php foreach ($errors as $key => $msg): ?>
                        <?php if (str_starts_with($key, "items_")): ?>
                            <div class="alert alert-error"><?php echo e($msg); ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>

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
                                    <td colspan="4" style="text-align:right">Tổng cộng:</td>
                                    <td class="numeric"><span class="grand-total" id="grandTotal">0 đ</span></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <div class="actions" style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
                    <a class="btn btn-outline" href="orders.php">Hủy</a>
                    <button class="btn btn-primary" type="submit">
                        <?php echo $isEdit ? "Cập nhật đơn" : "Tạo đơn hàng"; ?>
                    </button>
                </div>
            </form>
        </main>
    </div>

    <script>
        const MATERIALS = <?php echo json_encode($materials, JSON_UNESCAPED_UNICODE); ?>;
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
