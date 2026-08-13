Add-Type -AssemblyName System.Drawing

$inputPath = 'c:\PKL\coreERP\apps\control-plane\public\images\auth-illustration.jpg'
$outputPath = 'c:\PKL\coreERP\apps\control-plane\public\images\auth-illustration.png'

$img = [System.Drawing.Bitmap]::FromFile($inputPath)
$bmp = New-Object System.Drawing.Bitmap($img.Width, $img.Height, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.DrawImage($img, 0, 0, $img.Width, $img.Height)
$g.Dispose()
$img.Dispose()

for ($y = 0; $y -lt $bmp.Height; $y++) {
    for ($x = 0; $x -lt $bmp.Width; $x++) {
        $pixel = $bmp.GetPixel($x, $y)
        $r = $pixel.R
        $gColor = $pixel.G
        $b = $pixel.B
        $max = [Math]::Max($r, [Math]::Max($gColor, $b))
        
        if ($max -lt 28) {
            $bmp.SetPixel($x, $y, [System.Drawing.Color]::FromArgb(0, 0, 0, 0))
        } else {
            # Smooth alpha feathering for dark/black background transition
            $alpha = [Math]::Min(255, [int]($max * 2.2))
            $bmp.SetPixel($x, $y, [System.Drawing.Color]::FromArgb($alpha, $r, $gColor, $b))
        }
    }
}

$bmp.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)
$bmp.Dispose()
Write-Host 'PNG transparent conversion completed successfully!'
