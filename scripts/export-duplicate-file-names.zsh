#!/usr/bin/env python3
"""
Script to read a CSV of file paths, rename them with parent folder prefix,
and create a zip archive for review.

Usage: python csv_to_zip.py input.csv [output.zip]
"""

import sys
import csv
import os
from pathlib import Path
import zipfile
import shutil
from tempfile import mkdtemp
import json
from datetime import datetime, timezone


def process_csv_to_zip(csv_file, output_zip=None):
    """
    Read CSV of file paths, copy them with renamed format (parentfolder-filename),
    and create a zip archive.
    
    Args:
        csv_file: Path to CSV file containing file paths
        output_zip: Optional output zip filename (default: files-YYYY-MM-DD-HHMMSS.zip)
    """
    if output_zip is None:
        timestamp = datetime.now().strftime("%Y-%m-%d-%H%M%S")
        output_zip = f"files-{timestamp}.zip"
    
    # Create temporary directory for renamed files
    temp_dir = mkdtemp(prefix="csv_zip_")
    
    try:
        # Read CSV file
        with open(csv_file, 'r') as f:
            reader = csv.reader(f)
            file_paths = []
            
            for row in reader:
                # Handle each column in the row
                for cell in row:
                    cell = cell.strip()
                    if cell:  # Skip empty cells
                        file_paths.append(cell)
        
        if not file_paths:
            print("No file paths found in CSV")
            return
        
        print(f"Found {len(file_paths)} files to process")
        
        # Process each file
        copied_count = 0
        packages = []
        
        for file_path in file_paths:
            if not os.path.isfile(file_path):
                print(f"Warning: File not found or not a file: {file_path}")
                continue
            
            path = Path(file_path)
            parent_folder = path.parent.name
            filename = path.name
            
            # Create new filename: parentfolder-filename
            new_filename = f"{parent_folder}-{filename}"
            new_path = os.path.join(temp_dir, new_filename)
            
            # Copy file to temp directory with new name
            shutil.copy2(file_path, new_path)
            
            # Extract package name from composer.json if it exists
            package_name = None
            description = None
            if filename == 'composer.json':
                try:
                    with open(file_path, 'r') as f:
                        composer_data = json.load(f)
                        package_name = composer_data.get('name')
                        description = composer_data.get('description')
                except (json.JSONDecodeError, IOError):
                    pass
            
            # Track package info for manifest
            package_info = {
                "dir": parent_folder,
                "file": new_filename
            }
            if package_name:
                package_info["name"] = package_name
            if description:
                package_info["description"] = description
            
            packages.append(package_info)
            
            # Print one-line summary
            summary = f"✓ {parent_folder}/{filename}"
            if package_name:
                summary += f" → {package_name}"
            if description:
                summary += f" | {description[:60]}{'...' if len(description) > 60 else ''}"
            print(summary)
            
            copied_count += 1
        
        if copied_count == 0:
            print("No files were copied")
            return
        
        # Generate manifest
        manifest = {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "total_files": copied_count,
            "packages": packages
        }
        
        # Write manifest to temp directory
        manifest_path = os.path.join(temp_dir, "MANIFEST.json")
        with open(manifest_path, 'w') as f:
            json.dump(manifest, f, indent=2)
        
        print(f"\n📋 Generated MANIFEST.json with {copied_count} packages")
        
        # Create zip archive
        with zipfile.ZipFile(output_zip, 'w', zipfile.ZIP_DEFLATED) as zipf:
            for root, dirs, files in os.walk(temp_dir):
                for file in files:
                    file_path = os.path.join(root, file)
                    arcname = file  # Just the filename, not the temp dir path
                    zipf.write(file_path, arcname)
        
        print(f"\n✅ Successfully created {output_zip} with {copied_count} files + manifest")
        
    finally:
        # Clean up temporary directory
        shutil.rmtree(temp_dir)


def main():
    if len(sys.argv) < 2:
        print("Usage: python csv_to_zip.py input.csv [output.zip]")
        print("\nExample:")
        print("  python csv_to_zip.py files.csv")
        print("  python csv_to_zip.py files.csv composer-files.zip")
        print("\nDefault output: files-YYYY-MM-DD-HHMMSS.zip")
        sys.exit(1)
    
    csv_file = sys.argv[1]
    output_zip = sys.argv[2] if len(sys.argv) > 2 else None
    
    if not os.path.exists(csv_file):
        print(f"Error: CSV file not found: {csv_file}")
        sys.exit(1)
    
    process_csv_to_zip(csv_file, output_zip)


if __name__ == "__main__":
    main()