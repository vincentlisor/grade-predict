
def read_gwa(prompt):
    while True:
        try:
            value = float(input(prompt))
            if 1.00 <= value <= 5.00:
                return value
            print("GWA or grade must be between 1.00 and 5.00.")
        except ValueError:
            print("Invalid input. Example: 1.50")


def check_eligibility(gwa, subject_grades):
    # School eligibility rules
    has_disqualifying_grade = any(
        grade == 2.00 for grade in subject_grades
    )

    if has_disqualifying_grade:
        return (
            "Disqualified",
            "May subject grade na 2.00."
        )

    if 1.00 <= gwa <= 1.25:
        return (
            "Possible President's Lister (PL)",
            "Pasok ang GWA sa PL range; kailangan pa rin ng final verification."
        )

    if 1.25 < gwa <= 1.75:
        return (
            "Possible Dean's Lister (DL)",
            "Pasok ang GWA sa DL range; kailangan pa rin ng final verification."
        )

    return (
        "Not qualified based on GWA",
        "Hindi pasok sa PL/DL GWA ranges."
    )


def main():
    print("=== DL/PL ELIGIBILITY CHECKER ===")
    print("Use predicted GWA for screening or actual GWA for final checking.")
    print("Enter subject grades one at a time.")

    gwa = read_gwa("Enter GWA: ")

    while True:
        try:
            count = int(input("Number of subject grades: "))
            if count > 0:
                break
            print("Enter at least 1 subject.")
        except ValueError:
            print("Enter a valid whole number.")

    subject_grades = []

    for i in range(count):
        grade = read_gwa(f"Subject {i + 1} grade: ")
        subject_grades.append(grade)

    status, reason = check_eligibility(gwa, subject_grades)

    print("\n=== SCREENING RESULT ===")
    print(f"GWA: {gwa:.2f}")
    print(f"Subject grades: {subject_grades}")
    print(f"Status: {status}")
    print(f"Reason: {reason}")


if __name__ == "__main__":
    main()
