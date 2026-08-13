Add-Type -AssemblyName System.Drawing

$bgPath = 'c:\PKL\coreERP\apps\control-plane\public\images\auth-bg-full.jpg'
$laptopPath = 'c:\PKL\coreERP\apps\control-plane\public\images\auth-illustration.png'
$outputPath = 'c:\PKL\coreERP\apps\control-plane\public\images\auth-bg-compact.jpg'

$bg = [System.Drawing.Bitmap]::FromFile($bgPath)
$laptop = [System.Drawing.Bitmap]::FromFile($laptopPath)

$result = New-Object System.Drawing.Bitmap($bg.Width, $bg.Height, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
$g = [System.Drawing.Graphics]::FromImage($result)
$g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality

# 1. Draw original full background
$g.DrawImage($bg, 0, 0, $bg.Width, $bg.Height)

# 2. Cover the original large laptop region in the center with a smooth sampled gradient patch
# Center bounds of original laptop in auth-bg-full.jpg: x=300 to 650, y=150 to 520 (on a 1024x680 img)
$rect = New-Object System.Drawing.Rectangle(
    [int]($bg.Width * 0.28),
    [int]($bg.Height * 0.18),
    [int]($bg.Width * 0.38),
    [int]($bg.Height * 0.65)
)

# Sample background color around the laptop center
$colorLeft = $bg.GetPixel([int]($bg.Width * 0.25), [int]($bg.Height * 0.50))
$colorRight = $bg.GetPixel([int]($bg.Width * 0.68), [int]($bg.Height * 0.50))

$brush = New-Object System.Drawing.Drawing2D.LinearGradientBrush(
    $rect,
    $colorLeft,
    $colorRight,
    [System.Drawing.Drawing2D.LinearGradientMode]::Horizontal
)
$g.FillRectangle($brush, $rect)
$brush.Dispose()

# 3. Draw smaller laptop in the center (reduced size ~ 65% of original)
$targetW = [int]($laptop.Width * 0.65)
$targetH = [int]($laptop.Height * 0.65)
$targetX = [int]($bg.Width * 0.44 - $targetW / 2)
$targetY = [int]($bg.Height * 0.50 - $targetH / 2)

$g.DrawImage($laptop, $targetX, $targetY, $targetW, $targetH)

$g.Dispose()
$bg.Dispose()
$laptop.Dispose()

$result.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Jpeg)
$result.Dispose()

Write-Host 'Compact background image generated successfully!'
