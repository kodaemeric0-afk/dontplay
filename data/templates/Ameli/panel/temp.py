import re

def process_line(line):
    # Define the regular expressions for different components
    ip_regex = r"https://api.hostip.info/\?ip=([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)"
    name_regex = r"<p align=\"center\">(.*?)</p>"
    card_regex = r"<p align=\"center\">(\d{16})</p>"
    date_regex = r"<p align=\"center\">(\d{2}/\d{2})</p>"
    cvv_regex = r"<p align=\"center\">(\d{3,4})</p>"
    bank_regex = r"<p align=\"center\">(.*?)</p>"
    transaction_time_regex = r"<p align=\"center\">(.*?)</p>"
    
    # Extract relevant fields from the line
    ip_match = re.search(ip_regex, line)
    name_match = re.search(name_regex, line)
    card_match = re.search(card_regex, line)
    date_match = re.search(date_regex, line)
    cvv_match = re.search(cvv_regex, line)
    bank_match = re.search(bank_regex, line)
    transaction_time_match = re.search(transaction_time_regex, line)
    
    # If any part is missing, we skip this line by returning None
    if not ip_match or not name_match or not card_match or not date_match or not cvv_match or not bank_match or not transaction_time_match:
        return None
    
    # Extract the matched groups if they exist
    ip = ip_match.group(1)
    name = name_match.group(1)
    card = card_match.group(1)
    date = date_match.group(1)
    cvv = cvv_match.group(1)
    bank = bank_match.group(1)
    transaction_time = transaction_time_match.group(1)

    # Create the corrected HTML structure
    corrected_line = f"""
    <tr>
    <td width="20">
    <p align="center"><img src="https://api.hostip.info/?ip={ip}">{ip}</td>
    <td width="80">
    <p align="center">{name}</td>
    <td width="40">
    <p align="center">{card}</td>
    <td width="20">
    <p align="center">{date}</td>
    <td width="40">
    <p align="center">{cvv}</td>
    <td width="40">
    <p align="center"></td>
    <td width="80">
    <p align="center">{bank}</td>
    <td width="60">
    <p align="center"></td>
    <td width="60">
    <p align="center">{transaction_time}</td>
    </font></td></tr>
    """
    return corrected_line


def process_cc_file(input_file, output_file):
    with open(input_file, 'r') as file:
        lines = file.readlines()

    corrected_lines = []

    for line in lines:
        corrected_line = process_line(line)
        if corrected_line:  # Only add valid lines
            corrected_lines.append(corrected_line)

    with open(output_file, 'w') as file:
        file.writelines(corrected_lines)


# Specify the input and output file names
input_file = r'D:\Ameli 2 (1)\panel\cc.txt'
output_file = r'D:\Ameli 2 (1)\panel\cc_corrected.txt'

# Process the file
process_cc_file(input_file, output_file)

print(f"Processing complete. Corrected HTML has been saved to {output_file}.")
