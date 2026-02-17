# CDR Local Upload - Testing Guide

## Overview
This document describes how to upload CDR files from your local computer for testing purposes when the FTP server is not accessible.

## Two Options Available

### Option 1: FTP Server Upload (Production)
```bash
php artisan cdr:run
```
This is the original method that downloads files from the FTP server and processes them.

### Option 2: Local Computer Upload (Testing)
```bash
php artisan cdr:run-local```
This new method allows you to upload CDR files directly from your local computer.

## Using Local Upload

### Quick Start
Simply run the helper script:
```bash
./upload-local-cdr.sh
```

### Default Paths
The command uses these default Windows paths (converted to WSL):
- **MMG files**: `C:\Users\Ahmed Amin Bejoui\Desktop\CDR MMG`
  - WSL path: `/mnt/c/Users/Ahmed Amin Bejoui/Desktop/CDR MMG`
- **OCC files**: `C:\Users\Ahmed Amin Bejoui\Desktop\CDR OCC`
  - WSL path: `/mnt/c/Users/Ahmed Amin Bejoui/Desktop/CDR OCC`

### Custom Paths
You can specify different paths using command options:
```bash
php artisan cdr:run-local --mmg-path="/path/to/mmg/files" --occ-path="/path/to/occ/files"
```

### Example with Custom Paths
```bash
php artisan cdr:run-local \
  --mmg-path="/mnt/d/Data/CDR/MMG" \
  --occ-path="/mnt/d/Data/CDR/OCC"
```

## How It Works

1. **File Discovery**: Scans the specified directories for `.csv` files
2. **Duplicate Check**: Skips files that have already been successfully processed
3. **Copy to Storage**: Copies files to `storage/app/cdr/IN/{MMG|OCC}/`
4. **Validation**: Validates CSV format (quotes, column count, etc.)
5. **Database Upload**: Inserts data to Oracle staging tables (`RA_T_TMP_MMG`, `RA_T_TMP_OCC`)
6. **Verification**: Confirms row counts match between CSV and database
7. **Archive**: Moves successful files to `storage/app/cdr/OUT/{MMG|OCC}/`
8. **Error Handling**: Moves failed files to `storage/app/cdr/ERR/{MMG|OCC}/`

## Monitoring Results

### Check Audit Log
```sql
SELECT * FROM LOAD_AUDIT ORDER BY LOAD_TS DESC;
```

### Check Processing Status
```bash
# View files in different stages
ls -lh storage/app/cdr/IN/MMG/
ls -lh storage/app/cdr/IN/OCC/
ls -lh storage/app/cdr/OUT/MMG/
ls -lh storage/app/cdr/OUT/OCC/
ls -lh storage/app/cdr/ERR/MMG/
ls -lh storage/app/cdr/ERR/OCC/
```

### Check Database Records
```sql
-- Check MMG records
SELECT COUNT(*) FROM RA_T_TMP_MMG;

-- Check OCC records
SELECT COUNT(*) FROM RA_T_TMP_OCC;

-- View recent uploads
SELECT SOURCE_FILE, COUNT(*) as ROWS
FROM RA_T_TMP_MMG
GROUP BY SOURCE_FILE
ORDER BY MAX(LOAD_TS) DESC;
```

## Important Notes

1. **Both Options Remain Available**: The FTP upload option (`cdr:run`) is still fully functional and unchanged.

2. **Same Processing Logic**: Both methods use identical validation and database upload logic.

3. **Duplicate Prevention**: The system prevents re-processing files based on filename and filesize.

4. **File Requirements**:
   - Must be CSV format (`.csv` extension)
   - Must have valid header row
   - Must have consistent column counts
   - Must have balanced quotes in each line

5. **Storage**: Files are not deleted from your local directories. They remain in place after processing.

## Troubleshooting

### Files Not Found
- Verify the directory paths exist
- Check file permissions (files must be readable)
- Ensure files have `.csv` extension (lowercase)

### Path Conversion (Windows to WSL)
Windows path: `C:\Users\YourName\Desktop\Folder`
WSL path: `/mnt/c/Users/YourName/Desktop/Folder`

### Check Command is Available
```bash
php artisan list | grep cdr
```
You should see:
- `cdr:ftp-list`
- `cdr:run`
- `cdr:run-local`

### View Command Help
```bash
php artisan cdr:run-local --help
```

## When to Use Each Option

### Use FTP Upload (`cdr:run`)
- In production environment
- When you have FTP server access
- For scheduled/automated processing

### Use Local Upload (`cdr:run-local`)
- During development/testing
- When FTP server is unavailable
- For manual file processing
- For troubleshooting specific files
