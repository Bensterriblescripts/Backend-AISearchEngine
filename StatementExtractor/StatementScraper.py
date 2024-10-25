from decimal import Decimal
import pandas as pd

import os

visaallowed_petrol = ["Mobil", "Liquid", "Bp Connect", "Gull", "Gas Waikanae", "Kiwi Fuels"]
visaallowed_market = ["Pak N Save", "New World", "Woolworths", "Gilmours", "Four Square", "Countdown", "Moore Wilsons", "Te Moana Grocery"]
visaallowed_other = ["Mitre 10", "Darkhorse", "Warehous", "Webbs Auto Services"]
visapurchases_revise = []

path = "C:\\Local\\Scrape"
files = []
incoming = []
outgoing = []

totalin = 0
totalout = 0
totalcard = 0
totalcash = 0

selffunding = 0
selfpay = 0

for root, dirs, files in os.walk(path):
    for file in files:
        fullpath = os.path.join(root, file)
        df = pd.read_csv(fullpath)

        print("\n")
        for index, row in df.iterrows():

            # Transaction Type
            source = row['Type']

            # Details
            details = row['Details']
            part = row['Particulars']
            code = row['Code']
            ref = row['Reference']
            if code == "nan" or code == "C":
                code = ""

            # Money
            amount = Decimal(str(row['Amount']))
            if amount > 0:
                type = "incoming"
            else:
                type = "outgoing"
            date = row['Date']

            # # Incoming
            if type == "incoming":
                
                # Govt Deposits
                if source == "Direct Credit":
                    if "I.R.D" in details:
                        print("-- Skipping IRD credit")
                        continue
                # Self-Funding
                elif source == "Transfer":
                    if "06-0606-0878424-00" in details or "01-0546-0246467-30" in details:
                        selffunding += amount
                        continue
                # Income
                totalin += amount
                if source == "EFTPOS":
                    source = "Eftpos"
                    if "45109300" in details:
                        totalcard += amount
                    details = "Daily"
                if source == "Direct Credit":
                    source = "Direct"
                incoming.append([int(amount), source, details, date])

            # # Outgoing
            elif type == "outgoing":

                if "4835-****-****-0310" in details:
                    details = code

                # Non-Business Purchases
                if source == "Visa Purchase":
                    allowed = False
                    for petrol in visaallowed_petrol:
                        if petrol in details:
                            allowed = True
                            break
                    for market in visaallowed_market:
                        if market in details:
                            allowed = True
                            break
                    for other in visaallowed_other:
                        if other in details:
                            allowed = True
                            break

                    # GST Purchases
                    if allowed:
                        outgoing.append([int(amount), "Visa", details, date])
                    # Non-Business
                    else:
                        visapurchases_revise.append([int(amount), "Visa", details, date])
                    totalout += amount

                # Self Pay
                elif source == "Transfer":
                    if "06-0606-0878424-00" in details or "01-0546-0246467-30" in details:
                        selfpay += amount
                        continue
                # Expenses
                elif source == "Eft-Pos":
                    allowed = False
                    for petrol in visaallowed_petrol:
                        if petrol in details:
                            allowed = True
                            break
                    for market in visaallowed_market:
                        if market in details:
                            allowed = True
                            break
                    for other in visaallowed_other:
                        if other in details:
                            allowed = True
                            break
                    if allowed:
                        outgoing.append([int(amount), "Eftpos", details, date])
                    else:
                        visapurchases_revise.append([int(amount), "Eftpos", details, date])
                    totalout += amount

print("\nIncoming:")
for transaction in incoming:
    print(f"${transaction[0]:>10,.2f}\t{transaction[1]}\t\t{transaction[2]}\t\t\t{transaction[3]}")
print("\nOutgoing:")
for transaction in outgoing:
    print(f"${transaction[0]:>10,.2f}\t{transaction[1]}\t\t{transaction[2]}\t\t\t{transaction[3]}")

total = totalout + totalin
print(f"\nEnding Balance: {total}")
print(f"Income: {totalin}\n")
print(f"Expenses: {totalout}")
print(f"Income via EFTPOS: {totalcard}")
print(f"Income via Cash: {totalcash}\n")
print(f"Self-Funding: {selffunding}")
print(f"Self-Paid: {selfpay}")

print("\nReview:")
for purchase in visapurchases_revise:
    print(f"${purchase[0]:>10,.2f}\t{purchase[1]}\t\t{purchase[2]}\t\t\t{purchase[3]}")

